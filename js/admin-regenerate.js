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
  const loadRecentJobs = core.loadRecentJobs;
  const registerWorkflow = core.registerWorkflow;
  const rememberJob = core.rememberJob;
  const request = core.request;
  const responseError = core.responseError;
  const t = core.t;

  let regenToken = '';
  let regenRunning = false;
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
    $('#yotm_regen_force_note').toggleClass('yo-hidden', $('input[name="yotm_regen_mode"]:checked').val() !== 'force');
  }

  function lockRegenControls(locked) {
    $('input[name="yotm_regen_scope"], #yotm_regen_subpath, #yotm_regen_attachment_ids, input[name="yotm_regen_mode"]').prop('disabled', !!locked);
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
    $regenResults.html('<p><strong>' + escapeHtml(t('scope', 'Scope:')) + '</strong> ' + escapeHtml(data.scope_label || t('allMedia', 'All media')) + '</p><p><strong>' + escapeHtml(t('processed', 'Processed:')) + '</strong> ' + processed + '</p><p><strong>' + escapeHtml(t('regenerated', 'Regenerated:')) + '</strong> ' + escapeHtml(data.regenerated) + '</p><p><strong>' + escapeHtml(t('skipped', 'Skipped:')) + '</strong> ' + escapeHtml(data.skipped) + '</p><p><strong>' + escapeHtml(t('failed', 'Failed:')) + '</strong> ' + escapeHtml(data.failed) + '</p>');
  }

  function regenerateBatch() {
    const token = regenToken;
    if (!token || !regenRunning) {
      return;
    }
    request('yotm_regenerate_batch', {token: token, batch: 20}).done(function(response){
      if (token !== regenToken || !regenRunning) {
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
      const label = discovering
        ? String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', data.selection_scanned || 0)
        : t('processing', 'Processing…') + ' ' + data.processed + ' / ' + data.total;
      regenProgress(data.percent || 0, label, discovering);
      renderRegenState(data);
      if (!data.done) {
        window.setTimeout(regenerateBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      if (data.total_known && !parseInt(data.total || 0, 10)) {
        $regenResults.prepend(htmlNotice('notice-warning', t('noImageAttachments', 'No image attachments found.')));
      } else {
        $regenResults.prepend(htmlNotice(data.failed ? 'notice-warning' : 'notice-success', formatTemplate(t('doneRegenerated', 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.'), {'%1$s': data.regenerated, '%2$s': data.skipped, '%3$s': data.failed})) + errorDetails(data.errors));
      }
      clearRegenJob({keepResults: true, keepProgress: true});
      $regenResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== regenToken) {
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
    regenRunning = true;
    lockRegenControls(true);
    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden');
    $regenResults.empty();
    regenProgress(1, t('preparingRegenerationQueue', 'Preparing regeneration queue…'));
    const mode = $('input[name="yotm_regen_mode"]:checked').val() || 'missing';
    request('yotm_regenerate_prepare', {
      scope: $('input[name="yotm_regen_scope"]:checked').val() || 'all',
      subpath: $('#yotm_regen_subpath').val() || '',
      attachment_ids: $('#yotm_regen_attachment_ids').val() || '',
      only_missing: mode === 'missing' ? 1 : 0,
      force_all: mode === 'force' ? 1 : 0
    }).done(function(response){
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
      if (response.data.total_known !== false && !response.data.total) {
        $regenResults.html(htmlNotice('notice-warning', t('noImageAttachments', 'No image attachments found.')));
        cancelJob(regenToken).always(function(){ clearRegenJob({keepResults: true}); loadRecentJobs(); });
        return;
      }
      renderRegenState(response.data);
      regenerateBatch();
      loadRecentJobs();
    }).fail(function(){
      $regenResults.html(htmlNotice('notice-error', t('networkRegeneratePrepare', 'Network error during regenerate prepare.')));
      clearRegenJob({keepResults: true, preserveJob: !!regenToken});
    });
  }

  function resumeRegenerate(token) {
    if (!token || (regenToken === token && regenRunning)) {
      return;
    }
    regenToken = token;
    regenRunning = true;
    rememberJob('regenerate', token);
    activateTab('regenerate');
    lockRegenControls(true);
    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden');
    $regenResults.html(htmlNotice('notice-info', t('resumeAvailable', 'An unfinished job was found. Resuming it now…')));
    getJobStatus(token).done(function(response){
      if (regenToken !== token) {
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
      }
    }).fail(function(statusRequest){
      if (regenToken !== token) {
        return;
      }
      clearRegenJob({keepResults: true, preserveJob: !isMissingJobStatus(statusRequest)});
    });
  }

  $('input[name="yotm_regen_scope"]').on('change', toggleRegenScopeFields);
  $('input[name="yotm_regen_mode"]').on('change', toggleRegenModeNote);
  $regenRun.on('click', function(){ prepareRegenerate(); });
  $regenCancel.on('click', function(){
    if (!regenToken) {
      clearRegenJob({keepResults: true});
      return;
    }
    const token = regenToken;
    regenRunning = false;
    $regenCancel.prop('disabled', true);
    $regenStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token).done(function(){
      if (regenToken !== token) {
        return;
      }
      $regenResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.')));
      clearRegenJob({keepResults: true});
      $regenResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (regenToken !== token) {
        return;
      }
      regenRunning = true;
      $regenCancel.prop('disabled', false);
    });
  });

  toggleRegenScopeFields();
  toggleRegenModeNote();
  registerWorkflow('regenerate', {
    isActive: function(){ return !!regenToken; },
    prepare: prepareRegenerate,
    resume: resumeRegenerate
  });
})(jQuery, window.YOTMAdmin);
