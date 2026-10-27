(function($, core){
  'use strict';

  if (!core) {
    return;
  }

  const activateTab = core.activateTab;
  const cancelJob = core.cancelJob;
  const errorDetails = core.errorDetails;
  const escapeHtml = core.escapeHtml;
  const forgetJob = core.forgetJob;
  const formatTemplate = core.formatTemplate;
  const getJobStatus = core.getJobStatus;
  const htmlNotice = core.htmlNotice;
  const isMissingJobStatus = core.isMissingJobStatus;
  const isJobStopping = core.isJobStopping;
  const stopPreparation = core.stopPreparation;
  const stopJobMessage = core.stopJobMessage;
  const loadRecentJobs = core.loadRecentJobs;
  const registerWorkflow = core.registerWorkflow;
  const rememberJob = core.rememberJob;
  const request = core.request;
  const responseError = core.responseError;
  const t = core.t;

  let regenToken = '';
  let regenRunning = false;
  let reportToken = '';
  let resultsToken = '';
  let resultsRequest = 0;
  let prepareRequest = 0;
  let mediaFrame;
  const config = window.YOTM || {};
  const $repair = $('#yotm_regen_repair');
  const $health = $('#yotm_health_results');

  function selectionCount() {
    const ids = String($('#yotm_regen_attachment_ids').val() || '').split(',').filter(Boolean);
    $('#yotm_regen_selection_count').text(ids.length === 1 ? t('oneImageSelected', '1 image selected') : String(t('selectedImageCount', '%s images selected')).replace('%s', ids.length));
  }

  function chooseImages() {
    if (regenRunning || regenToken || !window.wp || !window.wp.media) {
      return;
    }
    if (!mediaFrame) {
      mediaFrame = window.wp.media({
        title: t('chooseImages', 'Choose images'),
        button: {text: t('useSelectedImages', 'Use selected images')},
        library: {type: 'image'},
        multiple: true
      });
      mediaFrame.on('select', function(){
        const ids = mediaFrame.state().get('selection').toJSON().map(function(item){ return item.id; });
        if (ids.length > 500) {
          $('#yotm_regen_selection_error').html(htmlNotice('notice-error', t('selectionLimit', 'Choose up to 500 images at a time. Your previous selection has been retained.')));
          return;
        }
        $('#yotm_regen_attachment_ids').val(ids.join(','));
        $('#yotm_regen_selection_error').empty();
        $('input[name="yotm_regen_scope"][value="ids"]').prop('checked', true);
        toggleRegenScopeFields();
        selectionCount();
      });
      mediaFrame.on('open', function(){
        const selection = mediaFrame.state().get('selection');
        selection.reset();
        String($('#yotm_regen_attachment_ids').val() || '').split(',').filter(Boolean).forEach(function(id){
          selection.add(window.wp.media.attachment(parseInt(id, 10)));
        });
      });
    }
    mediaFrame.open();
  }

  function healthStatus(status) {
    return {
      healthy: t('healthHealthy', 'Healthy'), missing: t('healthMissing', 'Missing'),
      blocked: t('healthBlocked', 'Blocked'), unsupported: t('healthUnsupported', 'Unsupported'),
      not_needed: t('healthNotNeeded', 'Not needed'), disabled: t('healthDisabled', 'Disabled')
    }[status] || status || t('unknown', 'Unknown');
  }

  function healthReason(reason) {
    return {
      file_present: t('healthFilePresent', 'Thumbnail file is present.'),
      file_missing: t('healthFileMissing', 'Thumbnail file is missing.'),
      metadata_missing: t('healthMetadataMissing', 'Thumbnail metadata is missing.'),
      source_size_not_resizable: t('healthSourceTooSmall', 'This size is not needed for the source image dimensions.'),
      size_not_enabled: t('healthSizeNotEnabled', 'This thumbnail size is currently disabled.'),
      unsafe_thumbnail_path: t('healthUnsafePath', 'The thumbnail path could not be verified safely.'),
      invalid_size_definition: t('healthInvalidDefinition', 'This thumbnail size has an invalid definition.'),
      upscaled_size_definition: t('healthUpscaledDefinition', 'This thumbnail size would require enlarging the source image.')
    }[reason] || '';
  }

  function healthBadge(status, label) {
    const tone = {healthy: 'yo-safe', missing: 'yo-warning', blocked: 'yo-danger', unsupported: 'yo-warning'}[status] || 'yo-health-neutral';
    return '<span class="yo-badge ' + tone + '">' + escapeHtml(label || healthStatus(status)) + '</span>';
  }

  function renderHealthItem(item, operation) {
    const sizes = item.sizes || {};
    const order = ['blocked', 'missing', 'unsupported', 'healthy', 'not_needed', 'disabled'];
    const slugs = Object.keys(sizes).sort(function(a, b){
      const rank = status => order.includes(status) ? order.indexOf(status) : order.length;
      return rank(sizes[a].status) - rank(sizes[b].status);
    });
    let overview = '';
    order.forEach(function(status){
      const count = slugs.filter(slug => sizes[slug].status === status).length;
      if (count) {
        overview += healthBadge(status, healthStatus(status) + ': ' + count);
      }
    });
    let result = overview ? '<div class="yo-health-counts">' + overview + '</div>' : '';
    if (operation === 'repair') {
      result += '<p class="yo-health-restored"><strong>' + escapeHtml(t('healthRestored', 'Restored:')) + ' ' + escapeHtml(item.restored_count || 0) + '</strong>' + (item.restored_sizes && item.restored_sizes.length ? ' — ' + escapeHtml(item.restored_sizes.join(', ')) : '') + '</p>';
    }
    if (item.message && (item.status !== 'healthy' || item.item_status === 'failed' || item.item_status === 'skipped')) {
      result += '<p class="yo-health-message">' + escapeHtml(item.message) + '</p>';
    }
    if (item.action) {
      result += '<p class="yo-health-action"><strong>' + escapeHtml(t('healthAction', 'Suggested action')) + ':</strong> ' + escapeHtml(item.action) + '</p>';
    }
    if (slugs.length) {
      const detailsLabel = String(t('healthViewSizes', 'View size details (%s)')).replace('%s', slugs.length);
      const imageLabel = item.title || '#' + item.attachment_id;
      result += '<details class="yo-health-details"><summary>' + escapeHtml(detailsLabel) + '<span class="screen-reader-text"> — ' + escapeHtml(imageLabel) + ' (#' + escapeHtml(item.attachment_id) + ')</span></summary>';
      result += '<table class="widefat yo-health-sizes"><caption class="screen-reader-text">' + escapeHtml(imageLabel) + ' — ' + escapeHtml(t('healthSizeDetails', 'Thumbnail size details')) + '</caption><thead><tr><th scope="col">' + escapeHtml(t('size', 'Size')) + '</th><th scope="col">' + escapeHtml(t('status', 'Status')) + '</th><th scope="col">' + escapeHtml(t('healthDetails', 'Details')) + '</th></tr></thead><tbody>';
      slugs.forEach(function(slug){
        const size = sizes[slug];
        const reason = healthReason(size.reason);
        result += '<tr><th scope="row">' + escapeHtml(slug) + '</th><td>' + healthBadge(size.status) + '</td><td>' + escapeHtml(reason) + (size.action ? '<p>' + escapeHtml(size.action) + '</p>' : '') + '</td></tr>';
      });
      result += '</tbody></table></details>';
    }
    const itemStatus = {skipped: t('skipped', 'Skipped:'), failed: t('failed', 'Failed:')}[item.item_status];
    const jobResult = itemStatus ? '<p class="yo-health-job-result">' + escapeHtml(t('healthItemResult', 'Job result:')) + ' ' + escapeHtml(itemStatus) + '</p>' : '';
    return '<tr><th scope="row" class="yo-health-image">' + escapeHtml(item.title || '#' + item.attachment_id) + '<span class="yo-health-id">#' + escapeHtml(item.attachment_id) + '</span></th><td class="yo-health-status">' + healthBadge(item.status) + jobResult + '</td><td>' + result + '</td></tr>';
  }

  function loadHealthResults(token, page) {
    resultsToken = token;
    const requestId = ++resultsRequest;
    $health.html('<p>' + escapeHtml(t('healthLoading', 'Loading thumbnail results…')) + '</p>');
    request('yotm_thumbnail_health_results', {token: token, page: page || 1, per_page: 25}).done(function(response){
      if (resultsToken !== token || requestId !== resultsRequest) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $health.html(htmlNotice('notice-error', responseError(response, t('healthLoadFailed', 'Could not load thumbnail results.'))));
        return;
      }
      const data = response.data;
      if (data.operation === 'check' && data.done) {
        reportToken = token;
        $repair.removeClass('yo-hidden').prop('disabled', !!regenToken);
      }
      let html = '<p>' + escapeHtml(t('healthCoverage', 'Only currently enabled sizes and local upload files were checked. Frontend pages and CDN copies are outside this report.')) + '</p>';
      if (data.scope_label) {
        html += '<p><strong>' + escapeHtml(t('scope', 'Scope:')) + '</strong> ' + escapeHtml(data.scope_label) + '</p>';
      }
      if (data.inspected_at) {
        html += '<p><strong>' + escapeHtml(t('healthInspected', 'Inspected (UTC):')) + '</strong> ' + escapeHtml(data.inspected_at) + '</p>';
      }
      html += '<p>' + escapeHtml(t('healthNewCheck', 'Run a new check after changing enabled sizes or media files.')) + '</p>';
      if (!(data.items || []).length) {
        html += '<p>' + escapeHtml(t('healthNoResults', 'No thumbnail results are available yet.')) + '</p>';
      } else {
        html += '<div class="yo-table-scroll"><table class="widefat striped yo-health-table"><thead><tr><th scope="col">' + escapeHtml(t('healthTitle', 'Image')) + '</th><th scope="col">' + escapeHtml(t('status', 'Status')) + '</th><th scope="col">' + escapeHtml(t('healthThumbnailResults', 'Thumbnail results')) + '</th></tr></thead><tbody>';
        data.items.forEach(function(item){
          html += renderHealthItem(item, data.operation);
        });
        html += '</tbody></table></div>';
      }
      const current = parseInt(data.page || 1, 10);
      const pages = Math.max(1, parseInt(data.pages || 1, 10));
      html += '<p>' + escapeHtml(formatTemplate(t('pageOf', 'Page %1$s of %2$s'), {'%1$s': current, '%2$s': pages}));
      if (current > 1) {
        html += ' <button type="button" class="button yotm-health-page" data-page="' + (current - 1) + '">' + escapeHtml(t('previousPage', 'Previous')) + '</button>';
      }
      if (current < pages) {
        html += ' <button type="button" class="button yotm-health-page" data-page="' + (current + 1) + '">' + escapeHtml(t('nextPage', 'Next')) + '</button>';
      }
      $health.html(html + '</p>');
    }).fail(function(){
      if (resultsToken === token && requestId === resultsRequest) {
        $health.html(htmlNotice('notice-error', t('healthLoadFailed', 'Could not load thumbnail results.')));
      }
    });
  }
  const $regenProg = $('#yotm_regen_progress');
  const $regenBar = $('#yotm_regen_progress .bar');
  const $regenStat = $('#yotm_regen_status');
  const $regenResults = $('#yotm_regen_results');
  const $regenRun = $('#yotm_regen_run');
  const $regenCancel = $('#yotm_regen_cancel');

  function toggleRegenScopeFields() {
    const scope = $('input[name="yotm_regen_scope"]:checked').val();
    $('#yotm_regen_subpath_wrap').toggleClass('yo-hidden', scope !== 'subpath');
    $('#yotm_regen_ids_wrap').toggleClass('yo-hidden', scope !== 'ids');
  }

  function toggleRegenModeNote() {
    const force = $('input[name="yotm_regen_mode"]:checked').val() === 'force';
    $('#yotm_regen_force_note').toggleClass('yo-hidden', !force);
    $regenRun.text(force ? t('runForceRegenerate', 'Run force regeneration') : t('checkThumbnails', 'Check thumbnails'));
  }

  function lockRegenControls(locked) {
    $('input[name="yotm_regen_scope"], #yotm_regen_subpath, #yotm_regen_attachment_ids, #yotm_regen_choose, input[name="yotm_regen_mode"]').prop('disabled', !!locked);
  }

  function regenProgress(percent, label, indeterminate) {
    const value = Math.max(0, Math.min(100, parseFloat(percent) || 0));
    $regenProg.show().toggleClass('indeterminate', !!indeterminate).attr('aria-valuenow', value.toFixed(0));
    $regenProg.attr('aria-busy', indeterminate ? 'true' : 'false');
    $regenBar.css('width', Math.max(1, value) + '%');
    $regenStat.show().text(label || value.toFixed(1) + '%');
  }

  function clearRegenJob(options) {
    const opts = options || {};
    regenRunning = false;
    if (!opts.preserveJob) {
      regenToken = '';
      forgetJob('regenerate');
      lockRegenControls(false);
      $regenCancel.addClass('yo-hidden').prop('disabled', false);
    } else {
      lockRegenControls(true);
      $regenCancel.removeClass('yo-hidden').prop('disabled', false);
    }
    $regenRun.prop('disabled', !!opts.preserveJob);
    $repair.prop('disabled', !!opts.preserveJob);
    if (!opts.keepProgress) {
      $regenProg.hide().removeClass('indeterminate').attr({'aria-valuenow': '0', 'aria-busy': 'false'});
      $regenBar.css('width', '0');
      $regenStat.hide().text('');
    }
    if (!opts.keepResults) {
      $regenResults.empty();
    }
  }

  function renderRegenState(data) {
    const processed = data.total_known === false
      ? escapeHtml(String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', data.selection_scanned || 0))
      : escapeHtml(data.processed) + ' / ' + escapeHtml(data.total);
    const regenerated = data.operation === 'check' ? '' : '<p><strong>' + escapeHtml(t('regenerated', 'Regenerated:')) + '</strong> ' + escapeHtml(data.regenerated) + '</p>';
    $regenResults.html('<p><strong>' + escapeHtml(t('scope', 'Scope:')) + '</strong> ' + escapeHtml(data.scope_label || t('allMedia', 'All media')) + '</p><p><strong>' + escapeHtml(t('processed', 'Processed:')) + '</strong> ' + processed + '</p>' + regenerated + '<p><strong>' + escapeHtml(t('skipped', 'Skipped:')) + '</strong> ' + escapeHtml(data.skipped) + '</p><p><strong>' + escapeHtml(t('failed', 'Failed:')) + '</strong> ' + escapeHtml(data.failed) + '</p>');
  }

  function regenerateBatch() {
    const token = regenToken;
    if (!token || !regenRunning || isJobStopping(token)) {
      return;
    }
    request('yotm_regenerate_batch', {token: token, batch: 20}).done(function(response){
      if (token !== regenToken || !regenRunning || isJobStopping(token)) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $regenResults.prepend(htmlNotice('notice-error', t('batchFailed', 'Batch failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearRegenJob({keepResults: true, preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled') {
        $regenResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped.')));
        clearRegenJob({keepResults: true});
        loadRecentJobs();
        return;
      }
      const discovering = data.total_known === false;
      let label = discovering
        ? String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', data.selection_scanned || 0)
        : t('processing', 'Processing…') + ' ' + data.processed + ' / ' + data.total;
      if (data.phase === 'source_index') {
        label = t('indexingSources', 'Indexing authoritative media sources…');
      } else if (data.phase === 'selection') {
        label = t('preparingRegenerationQueue', 'Preparing regeneration queue…');
      }
      regenProgress(data.percent || 0, label, discovering || data.phase === 'source_index' || data.phase === 'selection');
      renderRegenState(data);
      if (!data.done) {
        window.setTimeout(function(){ if (token === regenToken && !isJobStopping(token)) { regenerateBatch(); } }, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      const completedToken = regenToken;
      if (data.operation === 'check' || data.operation === 'repair') {
        $regenResults.prepend(htmlNotice(data.failed ? 'notice-warning' : 'notice-success', data.operation === 'check' ? t('healthCheckDone', 'Check complete. Review the results, then choose Repair missing thumbnails to make changes.') : t('healthRepairDone', 'Repair complete. Results show the files restored by this job.')) + errorDetails(data.errors));
      } else if (data.total_known && !parseInt(data.total || 0, 10)) {
        $regenResults.prepend(htmlNotice('notice-warning', t('noImageAttachments', 'No image attachments found.')));
      } else {
        $regenResults.prepend(htmlNotice(data.failed ? 'notice-warning' : 'notice-success', formatTemplate(t('doneRegenerated', 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.'), {'%1$s': data.regenerated, '%2$s': data.skipped, '%3$s': data.failed})) + errorDetails(data.errors));
      }
      clearRegenJob({keepResults: true, keepProgress: true});
      if (data.operation === 'check' || data.operation === 'repair') {
        loadHealthResults(completedToken, 1);
      }
      $regenResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== regenToken || isJobStopping(token)) {
        return;
      }
      $regenResults.prepend(htmlNotice('notice-error', t('networkRegenerateBatch', 'Network error during regenerate batch.') + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearRegenJob({keepResults: true, preserveJob: true});
    });
  }

  function prepareRegenerate(options) {
    const opts = options || {};
    if (regenRunning || regenToken) {
      return;
    }
    if (opts.activateTab) {
      activateTab('regenerate');
    }
    const mode = $('input[name="yotm_regen_mode"]:checked').val() || 'missing';
    const scope = $('input[name="yotm_regen_scope"]:checked').val() || 'all';
    if (!opts.reportToken && scope === 'ids' && !$('#yotm_regen_attachment_ids').val()) {
      $('#yotm_regen_selection_error').html(htmlNotice('notice-error', t('selectionEmpty', 'Choose at least one image from the Media Library.')));
      return;
    }
    regenRunning = true;
    stopPreparation('regenerate', false);
    const requestId = ++prepareRequest;
    reportToken = '';
    resultsToken = '';
    ++resultsRequest;
    $repair.addClass('yo-hidden').prop('disabled', true);
    $health.empty();
    lockRegenControls(true);
    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden');
    $regenResults.empty();
    regenProgress(1, t('preparingRegenerationQueue', 'Preparing regeneration queue…'));
    const payload = opts.reportToken ? {operation: 'repair', report_token: opts.reportToken} : {
      scope: scope,
      subpath: $('#yotm_regen_subpath').val() || '',
      attachment_ids: $('#yotm_regen_attachment_ids').val() || '',
      only_missing: mode === 'missing' ? 1 : 0,
      force_all: mode === 'force' ? 1 : 0
    };
    if (!opts.reportToken && mode !== 'force') {
      payload.operation = 'check';
    }
    request('yotm_regenerate_prepare', payload).done(function(response){
      if (requestId !== prepareRequest || !regenRunning) {
        const lateToken = response && response.data && (response.data.token || response.data.resume_token);
        if (lateToken && lateToken !== regenToken) {
          if (!regenToken) { stopRegenerate(lateToken); }
          else { cancelJob(lateToken, function(job){ return request('yotm_regenerate_batch', {token: job.token, batch: 1}); }).always(loadRecentJobs); }
        } else if (response && !response.success) {
          stopPreparation('regenerate', false);
        }
        return;
      }
      if (!response || !response.success || !response.data) {
        const resumeToken = response && response.data ? response.data.resume_token : '';
        if (resumeToken) {
          resumeRegenerate(resumeToken);
          return;
        }
        $regenResults.html(htmlNotice('notice-error', t('prepareFailed', 'Prepare failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearRegenJob({keepResults: true});
        return;
      }
      regenToken = response.data.token;
      rememberJob('regenerate', regenToken);
      if (!response.data.operation && response.data.total_known !== false && !response.data.total) {
        $regenResults.html(htmlNotice('notice-warning', t('noImageAttachments', 'No image attachments found.')));
        cancelJob(regenToken).always(function(){ clearRegenJob({keepResults: true}); loadRecentJobs(); });
        return;
      }
      renderRegenState(response.data);
      regenerateBatch();
      loadRecentJobs();
    }).fail(function(){
      if (requestId !== prepareRequest || !regenRunning) {
        return;
      }
      $regenResults.html(htmlNotice('notice-error', t('networkRegeneratePrepare', 'Network error during regenerate prepare.')));
      clearRegenJob({keepResults: true, preserveJob: !!regenToken});
    });
  }

  function resumeRegenerate(token) {
    if (token && isJobStopping(token, 'regenerate')) {
      stopRegenerate(token);
      return;
    }
    if (!token || (regenToken === token && regenRunning)) {
      return;
    }
    ++prepareRequest;
    regenToken = token;
    regenRunning = true;
    reportToken = '';
    resultsToken = '';
    ++resultsRequest;
    $repair.addClass('yo-hidden').prop('disabled', true);
    $health.empty();
    rememberJob('regenerate', token);
    activateTab('regenerate');
    lockRegenControls(true);
    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden');
    $regenResults.html(htmlNotice('notice-info', t('resumeAvailable', 'An unfinished job was found. Resuming it now…')));
    getJobStatus(token).done(function(response){
      if (regenToken !== token || isJobStopping(token)) {
        return;
      }
      if (response && response.success && response.data && response.data.status === 'running') {
        const context = response.data.context || {};
        if (response.data.total_known === false || (context.selector === 'attached_meta_v2' && !context.selection_done)) {
          regenProgress(0, String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', context.selection_scanned || 0), true);
        }
        regenerateBatch();
      } else {
        clearRegenJob({keepResults: true});
        const data = response && response.success ? response.data : null;
        const operation = data && (data.operation || (data.context || {}).operation);
        if (data && (operation === 'check' || operation === 'repair')) {
          const context = data.context || {};
          renderRegenState(Object.assign({}, data, {operation: operation, scope_label: context.scope_label, regenerated: data.succeeded || 0, skipped: Math.max(0, (data.processed || 0) - (data.succeeded || 0) - (data.failed || 0))}));
          $regenResults.prepend(htmlNotice('notice-info', t('healthSavedResults', 'Saved job results. No new processing was started.')));
          loadHealthResults(token, 1);
        }
      }
    }).fail(function(statusRequest){
      if (regenToken !== token || isJobStopping(token)) {
        return;
      }
      clearRegenJob({keepResults: true, preserveJob: !isMissingJobStatus(statusRequest)});
    });
  }

  $('#yotm_regen_choose').on('click', chooseImages);
  $repair.on('click', function(){
    if (reportToken && !regenToken && !regenRunning) {
      prepareRegenerate({reportToken: reportToken});
    }
  });
  $health.on('click', '.yotm-health-page', function(){
    if (resultsToken) {
      loadHealthResults(resultsToken, parseInt($(this).data('page'), 10));
    }
  });
  if (config.mediaSelection && config.mediaSelection.requested) {
    $('input[name="yotm_regen_scope"][value="all"]').prop('checked', false);
    $('input[name="yotm_regen_scope"][value="ids"]').prop('checked', true);
    $('#yotm_regen_attachment_ids').val((config.mediaSelection.ids || []).join(','));
    if (config.mediaSelection.error) {
      $('#yotm_regen_selection_error').html(htmlNotice('notice-error', config.mediaSelection.error));
    }
  }
  selectionCount();
  $('input[name="yotm_regen_scope"]').on('change', toggleRegenScopeFields);
  $('input[name="yotm_regen_mode"]').on('change', toggleRegenModeNote);
  $regenRun.on('click', function(){ prepareRegenerate(); });
  function stopRegenerate(token) {
    token = token || regenToken;
    ++prepareRequest;
    regenRunning = false;
    if (!token) {
      stopPreparation('regenerate', true);
      clearRegenJob({keepResults: true});
      $regenRun.prop('disabled', true);
      $regenResults.prepend(htmlNotice('notice-info', t('stopAwaitingPrepare', 'Waiting for the preparation request to finish so its job can be stopped. If interrupted, reload this page to find and stop it.')));
      return;
    }
    regenToken = token;
    rememberJob('regenerate', token);
    lockRegenControls(true);
    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden').prop('disabled', true);
    $regenStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token, function(job){ return request('yotm_regenerate_batch', {token: job.token, batch: 1}); }).done(function(response){
      if (regenToken !== token) {
        return;
      }
      const job = response.data.job;
      const context = job.context || {};
      renderRegenState(Object.assign({}, job, {operation: context.operation, scope_label: context.scope_label, regenerated: job.succeeded || 0, skipped: Math.max(0, (job.processed || 0) - (job.succeeded || 0) - (job.failed || 0))}));
      $regenResults.prepend(htmlNotice('notice-warning', stopJobMessage(response)));
      clearRegenJob({keepResults: true});
      if (job.context && ['check', 'repair'].includes(job.context.operation)) { loadHealthResults(token, 1); }
      $regenResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(stopRequest){
      if (regenToken !== token) {
        return;
      }
      $regenResults.prepend(htmlNotice('notice-error', t('stopUnconfirmed', 'Stop has not been confirmed. Retry Stop job or reload this page to continue stopping; no new batch will be started.') + ' ' + responseError(stopRequest, t('unknownError', 'Unknown error'))));
      clearRegenJob({keepResults: true, preserveJob: true});
    });
  }

  $regenCancel.on('click', function(){ stopRegenerate(); });

  toggleRegenScopeFields();
  toggleRegenModeNote();
  registerWorkflow('regenerate', {
    isActive: function(){ return !!regenToken; },
    stop: stopRegenerate,
    prepare: prepareRegenerate,
    resume: resumeRegenerate
  });
})(jQuery, window.YOTMAdmin);
