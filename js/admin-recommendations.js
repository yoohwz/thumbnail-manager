(function($, core){
  'use strict';

  if (!core) {
    return;
  }

  const config = window.YOTM || {};
  const activateTab = core.activateTab;
  const cancelJob = core.cancelJob;
  const escapeHtml = core.escapeHtml;
  const forgetJob = core.forgetJob;
  const getJobStatus = core.getJobStatus;
  const htmlNotice = core.htmlNotice;
  const formatBytes = core.formatBytes;
  const formatTemplate = core.formatTemplate;
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

  let recommendToken = '';
  let recommendRunning = false;
  let prepareRequest = 0;
  let recommendationApplyItems = [];
  let recommendationCanApply = false;
  const $recommendProg = $('#yotm_recommend_progress');
  const $recommendBar = $('#yotm_recommend_progress .bar');
  const $recommendStat = $('#yotm_recommend_status');
  const $recommendResults = $('#yotm_recommend_results');
  const $recommendRun = $('#yotm_recommend_scan');
  const $recommendCancel = $('#yotm_recommend_cancel');

  function recommendationProgress(percent, label) {
    const value = Math.max(0, Math.min(100, parseFloat(percent) || 0));
    $recommendProg.show().attr('aria-valuenow', value.toFixed(0));
    $recommendBar.css('width', Math.max(1, value) + '%');
    $recommendStat.show().text(label);
  }

  function clearRecommendation(options) {
    const opts = options || {};
    recommendRunning = false;
    if (!opts.preserveJob) {
      recommendToken = '';
      forgetJob('recommendation');
      $recommendCancel.addClass('yo-hidden').prop('disabled', false);
    } else {
      $recommendCancel.removeClass('yo-hidden').prop('disabled', false);
    }
    $recommendRun.prop('disabled', !!opts.preserveJob);
    if (!opts.keepProgress) {
      $recommendProg.hide().attr('aria-valuenow', '0');
      $recommendBar.css('width', '0');
      $recommendStat.hide().text('');
    }
  }

  function recommendationConfidenceLabel(confidence) {
    return {
      high: t('confidenceHigh', 'High'),
      medium: t('confidenceMedium', 'Medium'),
      low: t('confidenceLow', 'Low')
    }[confidence] || '—';
  }

  function renderRecommendationEvidence(evidence) {
    if (!Array.isArray(evidence) || !evidence.length) {
      return '—';
    }
    let html = '<ul class="yo-evidence-list">';
    evidence.forEach(function(item){
      html += '<li>' + escapeHtml(item && item.description ? item.description : '') + '</li>';
    });
    return html + '</ul>';
  }

  function renderContentSamples(samples) {
    if (!Array.isArray(samples) || !samples.length) { return ''; }
    let html = '<ul class="yo-evidence-list">';
    samples.slice(0, 5).forEach(function(sample){
      if (!sample || typeof sample.url !== 'string' || !/^https?:\/\//i.test(sample.url)) { return; }
      const hints = Array.isArray(sample.kinds) ? sample.kinds.map(function(kind){
        return {class_size_hint: t('contentClassHint', 'Size class hint'), json_size_hint: t('contentJsonHint', 'Size setting hint')}[kind] || '';
      }).filter(Boolean).join(', ') : '';
      html += '<li><a href="' + escapeHtml(sample.url) + '">' + escapeHtml(sample.title || '#' + sample.post_id) + '</a>' + (hints ? ' — ' + escapeHtml(hints) : '') + '</li>';
    });
    return html + '</ul>';
  }

  function renderScanCoverage(data) {
    let html = '<p class="description">' + escapeHtml(t('recommendationEvidenceLimits', 'Content evidence samples show size-name hints in post content, not verified image usage. Policy protections are separate. Dynamic theme, plugin, custom-field and external usage is not observed.')) + '</p>';
    html += '<p class="description">' + escapeHtml(t('recommendationSampleLimits', 'Up to five accessible content examples are shown per size. The scan covers IDs present when it began; new or changed content may be missed.')) + '</p>';
    const stats = data.statistics;
    if (!stats || stats.schema_version !== 1 || !stats.complete) {
      return html + '<p class="description">' + escapeHtml(t('recommendationLegacyStatistics', 'Unique file statistics are unavailable for this saved scan. Run a new scan; legacy footprint totals may count shared references more than once.')) + '</p>';
    }
    html += '<p class="description yo-recommend-statistics">' + escapeHtml(formatTemplate(t('recommendationStatistics', 'Attachments scanned: %1$s. Metadata entries: %2$s. Unique checked files: %3$s (%4$s). Missing files: %5$s. Unreadable files: %6$s. Unverified metadata entries: %7$s.'), {
      '%1$s': stats.attachments_scanned, '%2$s': stats.metadata_entries, '%3$s': stats.checked_files,
      '%4$s': formatBytes(stats.checked_bytes), '%5$s': stats.missing_files, '%6$s': stats.unreadable_files, '%7$s': stats.unverified_entries
    })) + '</p><p class="description">' + escapeHtml(t('recommendationStatisticsLimits', 'File totals cover unique canonical paths referenced by registered-size metadata, observed during the scan. They are not a live snapshot or a complete uploads inventory. Per-size bytes count metadata references and may overlap.')) + '</p>';
    return html;
  }

  function renderRecommendationTable(items) {
    if (!Array.isArray(items) || !items.length) {
      return htmlNotice('notice-warning', t('noRecommendationData', 'No recommendation data found.'));
    }
    let html = '<div class="yo-table-scroll"><table class="widefat striped"><thead><tr><th>' + escapeHtml(t('size', 'Size')) + '</th><th>' + escapeHtml(t('dimensions', 'Dimensions')) + '</th><th>' + escapeHtml(t('status', 'Status')) + '</th><th>' + escapeHtml(t('confidence', 'Keep confidence')) + '</th><th>' + escapeHtml(t('evidence', 'Evidence')) + '</th><th>' + escapeHtml(t('metadataEntries', 'Metadata entries')) + '</th><th>' + escapeHtml(t('referenceBytes', 'Readable reference bytes')) + '</th><th>' + escapeHtml(t('reason', 'Reason')) + '</th><th>' + escapeHtml(t('recommendation', 'Recommendation')) + '</th></tr></thead><tbody>';
    items.forEach(function(item){
      const badgeClass = item.status === 'detected_reference' || item.status === 'used' || item.status === 'protected' ? 'yo-safe' : (item.status === 'danger' ? 'yo-danger' : 'yo-warning');
      html += '<tr><td><strong>' + escapeHtml(item.name || '') + '</strong></td><td>' + escapeHtml(item.dimensions || '—') + '</td><td><span class="yo-badge ' + badgeClass + '">' + escapeHtml(item.label || item.status || t('unknown', 'Unknown')) + '</span></td><td>' + escapeHtml(recommendationConfidenceLabel(item.confidence)) + '</td><td>' + renderRecommendationEvidence(item.evidence) + renderContentSamples(item.content_samples) + '</td><td>' + escapeHtml(item.generated_count || 0) + '</td><td>' + escapeHtml(formatBytes(item.bytes)) + '</td><td>' + escapeHtml(item.reason || '') + '</td><td>' + escapeHtml(item.recommendation || '') + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  function renderRecommendationReport(result) {
    const data = result || {};
    return renderScanCoverage(data) + renderRecommendationTable(data.items);
  }

  window.YOTMRecommendationReport = renderRecommendationReport;

  function renderRecommendationResult(result) {
    const data = result || {};
    $('#yotm_recommend_protected_count').text(data.protected_count || 0);
    $('#yotm_recommend_reference_count').text(data.detected_reference_count || 0);
    $('#yotm_recommend_unknown_count').text(data.unknown_count || 0);
    $('#yotm_recommend_generated_bytes').text(data.generated_bytes_human || '—');

    const items = Array.isArray(data.items) ? data.items : [];
    const schemaValid = parseInt(data.schema_version, 10) === 2;
    const signatureValid = !!data.registered_sizes_signature && data.registered_sizes_signature === (config.registeredSizesSignature || '');
    const actionsValid = items.length > 0 && items.every(function(item){
      return item && typeof item.name === 'string' && (item.apply_action === 'enable' || item.apply_action === 'preserve');
    });
    let notice = '';

    recommendationCanApply = schemaValid && signatureValid && actionsValid;
    recommendationApplyItems = recommendationCanApply ? items.slice() : [];

    if (!schemaValid) {
      notice = htmlNotice('notice-warning', t('legacyRecommendationResult', 'This result uses an older recommendation format. Run a new scan before applying recommendations.'));
    } else if (!signatureValid) {
      notice = htmlNotice('notice-warning', t('staleRecommendationResult', 'Registered image sizes changed after this scan. Run a new scan before applying recommendations.'));
    } else if (!actionsValid) {
      notice = htmlNotice('notice-error', t('invalidRecommendationResult', 'This recommendation result is incomplete. Run a new scan before applying recommendations.'));
    } else {
      notice = htmlNotice('notice-info', t('conservativeApplyReady', 'Safe recommendations are ready. Unknown sizes will keep their current setting.'));
    }

    $recommendResults.html(notice + renderRecommendationReport(data));
    $('#yotm_apply_recommendations').toggleClass('yo-hidden', !recommendationCanApply).prop('disabled', !recommendationCanApply);
    $('#yotm_recommend_go_prune').removeClass('yo-hidden');
    $recommendStat.text(t('recommendationScanCompleted', 'Scan completed.'));
  }

  function recommendationBatch() {
    const token = recommendToken;
    if (!token || !recommendRunning || isJobStopping(token)) {
      return;
    }
    request('yotm_recommend_batch', {token: token, batch: 100}).done(function(response){
      if (token !== recommendToken || !recommendRunning || isJobStopping(token)) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $recommendResults.html(htmlNotice('notice-error', t('recommendationScanFailed', 'Recommendation scan failed.') + ' ' + responseError(response, '')));
        clearRecommendation({preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled') {
        $recommendResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped.')));
        clearRecommendation();
        loadRecentJobs();
        return;
      }
      const label = data.phase === 'content' ? t('scanningContentReferences', 'Scanning content references…') : t('scanningAttachmentMetadata', 'Scanning attachment metadata…');
      recommendationProgress(data.percent || 0, label + ' ' + data.processed + ' / ' + data.total);
      if (!data.done) {
        window.setTimeout(function(){ if (token === recommendToken && !isJobStopping(token)) { recommendationBatch(); } }, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      renderRecommendationResult(data.result || {});
      clearRecommendation({keepProgress: true});
      $recommendResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== recommendToken || isJobStopping(token)) {
        return;
      }
      $recommendResults.html(htmlNotice('notice-error', t('networkRecommendationScan', 'Network error during recommendation scan.') + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearRecommendation({preserveJob: true});
    });
  }

  function prepareRecommendation() {
    if (recommendRunning || recommendToken) {
      return;
    }
    stopPreparation('recommendation', false);
    const requestId = ++prepareRequest;
    recommendRunning = true;
    recommendationCanApply = false;
    recommendationApplyItems = [];
    $recommendRun.prop('disabled', true);
    $recommendCancel.removeClass('yo-hidden');
    $recommendResults.empty();
    $('#yotm_apply_recommendations, #yotm_recommend_go_prune').addClass('yo-hidden');
    recommendationProgress(1, t('scanningMediaUsage', 'Scanning media usage…'));
    request('yotm_recommend_prepare').done(function(response){
      if (requestId !== prepareRequest || !recommendRunning) {
        const lateToken = response && response.data && (response.data.token || response.data.resume_token);
        if (lateToken && lateToken !== recommendToken) {
          if (!recommendToken) { stopRecommendation(lateToken); }
          else { cancelJob(lateToken).always(loadRecentJobs); }
        } else if (response && !response.success) {
          stopPreparation('recommendation', false);
        }
        return;
      }
      if (!response || !response.success || !response.data) {
        const resumeToken = response && response.data ? response.data.resume_token : '';
        if (resumeToken) {
          resumeRecommendation(resumeToken);
          return;
        }
        $recommendResults.html(htmlNotice('notice-error', t('recommendationScanFailed', 'Recommendation scan failed.') + ' ' + responseError(response, '')));
        clearRecommendation();
        return;
      }
      recommendToken = response.data.token;
      rememberJob('recommendation', recommendToken);
      recommendationBatch();
      loadRecentJobs();
    }).fail(function(){
      if (requestId !== prepareRequest || !recommendRunning) { return; }
      $recommendResults.html(htmlNotice('notice-error', t('networkRecommendationScan', 'Network error during recommendation scan.')));
      clearRecommendation({preserveJob: !!recommendToken});
    });
  }

  function resumeRecommendation(token) {
    if (token && isJobStopping(token, 'recommendation')) {
      stopRecommendation(token);
      return;
    }
    if (!token || (recommendToken === token && recommendRunning)) {
      return;
    }
    ++prepareRequest;
    recommendToken = token;
    recommendRunning = true;
    rememberJob('recommendation', token);
    $recommendRun.prop('disabled', true);
    $recommendCancel.removeClass('yo-hidden');
    getJobStatus(token).done(function(response){
      if (recommendToken !== token || isJobStopping(token)) {
        return;
      }
      if (!response || !response.success || !response.data) {
        clearRecommendation();
      } else if (response.data.status === 'completed' && response.data.context && response.data.context.result) {
        renderRecommendationResult(response.data.context.result);
        clearRecommendation({keepProgress: true});
      } else if (response.data.status === 'scanning') {
        recommendationBatch();
      } else {
        clearRecommendation();
      }
    }).fail(function(statusRequest){
      if (recommendToken !== token || isJobStopping(token)) {
        return;
      }
      clearRecommendation({preserveJob: !isMissingJobStatus(statusRequest)});
    });
  }

  $recommendRun.on('click', prepareRecommendation);
  function stopRecommendation(token) {
    token = token || recommendToken;
    ++prepareRequest;
    recommendRunning = false;
    if (!token) {
      stopPreparation('recommendation', true);
      clearRecommendation({keepResults: true});
      $recommendRun.prop('disabled', true);
      $recommendResults.prepend(htmlNotice('notice-info', t('stopAwaitingPrepare', 'Waiting for the preparation request to finish so its job can be stopped. If interrupted, reload this page to find and stop it.')));
      return;
    }
    recommendToken = token;
    rememberJob('recommendation', token);
    $recommendRun.prop('disabled', true);
    $recommendCancel.removeClass('yo-hidden').prop('disabled', true);
    $recommendStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token).done(function(response){
      if (recommendToken !== token) {
        return;
      }
      $recommendResults.prepend(htmlNotice('notice-warning', stopJobMessage(response)));
      clearRecommendation({keepResults: true});
      const job = response.data.job;
      if (job.status === 'completed' && job.context && job.context.result) { renderRecommendationResult(job.context.result); }
      $recommendResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(stopRequest){
      if (recommendToken !== token) {
        return;
      }
      $recommendResults.prepend(htmlNotice('notice-error', t('stopUnconfirmed', 'Stop has not been confirmed. Retry Stop job or reload this page to continue stopping; no new batch will be started.') + ' ' + responseError(stopRequest, t('unknownError', 'Unknown error'))));
      clearRecommendation({keepResults: true, preserveJob: true});
    });
  }

  $recommendCancel.on('click', function(){ stopRecommendation(); });

  $('#yotm_apply_recommendations').on('click', function(){
    if (!recommendationCanApply) {
      return;
    }
    recommendationApplyItems.forEach(function(item){
      if (item.apply_action !== 'enable') {
        return;
      }
      $('input[name="yotm_enable_sizes[]"]').each(function(){
        if (this.value === item.name) {
          this.checked = true;
        }
      });
    });
    activateTab('sizes');
  });
  $('#yotm_recommend_go_prune').on('click', function(){ activateTab('prune'); });

  registerWorkflow('recommendation', {
    isActive: function(){ return !!recommendToken; },
    stop: stopRecommendation,
    resume: resumeRecommendation
  });
})(jQuery, window.YOTMAdmin);
