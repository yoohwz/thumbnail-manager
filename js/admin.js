(function($){
  'use strict';

  const config  = window.YOTM || {};
  const i18n    = config.i18n || {};
  const ajaxurl = config.ajaxurl || window.ajaxurl || '';
  const nonce   = config.nonce || '';
  const siteId  = config.siteId || 0;
  const activeStatuses = ['scanning', 'running', 'awaiting_approval', 'approved', 'deleting'];
  const workflowTypes = new Set(['prune', 'regenerate', 'recommendation']);
  const workflows = Object.create(null);
  const stoppedTokens = new Set();
  const stopRequests = new Map();
  const terminalStatuses = ['cancelled', 'completed', 'expired'];

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function responseError(response, fallback) {
    const payload = response && response.responseJSON ? response.responseJSON : response;
    if (payload && payload.data && payload.data.msg) {
      return payload.data.msg;
    }
    const status = parseInt(response && response.status || 0, 10);
    return status ? fallback + ' (HTTP ' + status + ')' : fallback;
  }

  function htmlNotice(cls, message) {
    const safeClass = String(cls || '').replace(/[^a-z0-9_\-\s]/ig, '');
    const role = /notice-(error|warning)/.test(safeClass) ? 'alert' : 'status';
    return '<div class="notice ' + safeClass + '" role="' + role + '"><p>' + escapeHtml(message) + '</p></div>';
  }

  function formatBytes(bytes) {
    let value = Math.max(0, parseInt(bytes || 0, 10));
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit += 1;
    }
    return (unit === 0 ? value.toFixed(0) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
  }

  function parseUtcDate(value) {
    if (!value) {
      return null;
    }
    const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T') + 'Z';
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function formatDate(value) {
    const date = parseUtcDate(value);
    return date ? date.toLocaleString() : '—';
  }

  function formatTemplate(value, replacements) {
    let output = String(value || '');
    Object.keys(replacements || {}).forEach(function(key){
      output = output.replace(key, replacements[key]);
    });
    return output;
  }

  function errorDetails(errors) {
    if (!Array.isArray(errors) || !errors.length) {
      return '';
    }
    let html = '<details class="yo-error-details"><summary>' + escapeHtml(t('errorsTitle', 'Error details')) + ' (' + errors.length + ')</summary><ul class="yo-error-list">';
    errors.forEach(function(item){
      const identity = item.path || (item.id ? '#' + item.id : item.item_key) || t('unknownPath', 'Unknown item');
      html += '<li><code>' + escapeHtml(identity) + '</code> — ' + escapeHtml(item.error || '') + '</li>';
    });
    return html + '</ul></details>';
  }

  function storageKey(type) {
    return 'yotm_job_' + siteId + '_' + type;
  }

  function rememberJob(type, token) {
    try {
      window.localStorage.setItem(storageKey(type), token || '');
    } catch (error) {}
  }

  function forgetJob(type) {
    try {
      window.localStorage.removeItem(storageKey(type));
    } catch (error) {}
  }

  function recallJob(type) {
    try {
      return window.localStorage.getItem(storageKey(type)) || '';
    } catch (error) {
      return '';
    }
  }

  function request(action, data) {
    return $.post(ajaxurl, Object.assign({}, data || {}, {action: action, nonce: nonce}));
  }

  function stopStorageKey(token) {
    return 'yotm_stop_' + siteId + '_' + (config.userId || 0) + '_' + token;
  }

  function stopPreparation(type, pending) {
    const key = stopStorageKey('prepare_' + type);
    try {
      if (pending) { window.localStorage.setItem(key, '1'); }
      else { window.localStorage.removeItem(key); }
    } catch (error) {}
  }

  function isJobStopping(token, type) {
    if (stoppedTokens.has(token)) {
      return true;
    }
    try {
      return !!window.localStorage.getItem(stopStorageKey(token)) || !!(type && window.localStorage.getItem(stopStorageKey('prepare_' + type)));
    } catch (error) {
      return false;
    }
  }

  function stopJobMessage(response) {
    const status = response.data.job.status;
    if (status === 'completed') {
      return t('jobCompletedBeforeStop', 'The job completed before it could be stopped. Review its results.');
    }
    if (status === 'expired') {
      return t('jobExpiredBeforeStop', 'The job has expired. No new work will be started.');
    }
    return t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.');
  }

  function cancelJob(token, recover) {
    stoppedTokens.add(token);
    try {
      window.localStorage.setItem(stopStorageKey(token), '1');
    } catch (error) {}
    if (stopRequests.has(token)) {
      return stopRequests.get(token);
    }
    const result = $.Deferred();
    const promise = result.promise();
    stopRequests.set(token, promise);

    function fail(response) {
      stopRequests.delete(token);
      result.reject(response);
    }

    function finish(job) {
      try {
        window.localStorage.removeItem(stopStorageKey(token));
      } catch (error) {}
      stopPreparation(job.type, false);
      stopRequests.delete(token);
      result.resolve({success: true, data: {cancelled: job.status === 'cancelled', job: job}});
    }

    function retry() {
      window.setTimeout(attempt, 250);
    }

    function inspect() {
      getJobStatus(token).done(function(response){
        const job = response && response.success && response.data;
        if (!job || job.token !== token) {
          fail(response);
          return;
        }
        if (terminalStatuses.includes(job.status)) {
          finish(job);
          return;
        }
        const context = job.context || {};
        if (context.recovery_only && context.recovery_terminal_status === 'cancelled') {
          if (typeof recover !== 'function') {
            fail(response);
            return;
          }
          // Only the server's persisted recovery-only state permits this request.
          recover(job).done(function(recovered){
            if (!recovered || !recovered.success) {
              fail(recovered);
              return;
            }
            retry();
          }).fail(fail);
        } else {
          retry();
        }
      }).fail(fail);
    }

    function attempt() {
      request('yotm_job_cancel', {token: token}).done(function(response){
        const job = response && response.success && response.data && response.data.job;
        if (job && job.token === token && terminalStatuses.includes(job.status)) {
          finish(job);
        } else {
          fail(response);
        }
      }).fail(function(response){
        const data = response && response.responseJSON && response.responseJSON.data;
        if (response && response.status === 409 && data && data.code === 'yotm_job_cancel_busy') {
          inspect();
        } else {
          fail(response);
        }
      });
    }
    attempt();
    return promise;
  }

  function getJobStatus(token) {
    return request('yotm_job_status', {token: token});
  }

  function isMissingJobStatus(request) {
    return !!request && parseInt(request.status || 0, 10) === 404;
  }

  function registerWorkflow(type, workflow) {
    if (!workflowTypes.has(type) || !workflow || typeof workflow.resume !== 'function') {
      return;
    }
    workflows[type] = workflow;
    if ([...workflowTypes].every(function(workflowType){ return !!workflows[workflowType]; })) {
      start();
    }
  }

  function resumeWorkflow(type, token) {
    const workflow = workflows[type];
    if (!token || !workflow || (typeof workflow.isActive === 'function' && workflow.isActive())) {
      return false;
    }
    if (isJobStopping(token, type) && typeof workflow.stop === 'function') {
      workflow.stop(token);
    } else {
      workflow.resume(token);
    }
    return true;
  }

  function activateTab(tab) {
    $('#yotm_tabs .yo-tab').removeClass('active').attr({'aria-selected': 'false', tabindex: '-1'});
    $('#yotm_tabs .yo-tab[data-tab="' + tab + '"]').addClass('active').attr({'aria-selected': 'true', tabindex: '0'});
    $('.yo-panel').removeClass('active').prop('hidden', true);
    $('#yotm_panel_' + tab).addClass('active').prop('hidden', false);
  }

  $('#yotm_tabs .yo-tab').on('click', function(){
    activateTab($(this).data('tab'));
  }).on('keydown', function(event){
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
    if (!keys.includes(event.key)) {
      return;
    }
    event.preventDefault();
    const $tabs = $('#yotm_tabs .yo-tab');
    const index = $tabs.index(this);
    let next = index;
    if (event.key === 'ArrowLeft') {
      next = index <= 0 ? $tabs.length - 1 : index - 1;
    } else if (event.key === 'ArrowRight') {
      next = index >= $tabs.length - 1 ? 0 : index + 1;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = $tabs.length - 1;
    }
    const $next = $tabs.eq(next);
    activateTab($next.data('tab'));
    $next.trigger('focus');
  });

  function jobTypeLabel(type, operation) {
    if (type === 'regenerate' && operation === 'check') { return t('jobThumbnailCheck', 'Check thumbnails'); }
    if (type === 'regenerate' && operation === 'repair') { return t('jobThumbnailRepair', 'Repair missing thumbnails'); }
    return {
      prune: t('jobPrune', 'Prune files'),
      regenerate: t('jobRegenerate', 'Regenerate'),
      recommendation: t('jobRecommendation', 'Recommendations')
    }[type] || type;
  }

  function resultActions(token) {
    return token ? '<p><button type="button" class="button yotm-open-report" data-token="' + escapeHtml(token) + '">' + escapeHtml(t('viewResultsReport', 'View results / download report')) + '</button></p>' : '';
  }

  function jobStatusLabel(status) {
    return {
      scanning: t('statusScanning', 'Scanning'),
      running: t('statusRunning', 'Running'),
      awaiting_approval: t('statusAwaitingApproval', 'Awaiting review'),
      approved: t('statusApproved', 'Approved'),
      deleting: t('statusDeleting', 'Deleting'),
      completed: t('statusCompleted', 'Completed'),
      cancelled: t('statusCancelled', 'Stopped'),
      expired: t('statusExpired', 'Expired')
    }[status] || status;
  }

  function tabForJob(type) {
    return type === 'recommendation' ? 'recommendations' : type;
  }

  function jobProgress(job) {
    if (job.status === 'completed') {
      return 100;
    }
    const total = parseInt(job.total || 0, 10);
    const processed = parseInt(job.processed || 0, 10);
    return total > 0 ? Math.min(99.9, processed / total * 100) : 0;
  }

  let recentActiveJob = null;

  function renderRecentJobs(jobs) {
    const list = Array.isArray(jobs) ? jobs : [];
    const $body = $('#yotm_recent_jobs_body').empty();
    if (!list.length) {
      $body.append('<tr><td colspan="4">' + escapeHtml(t('noRecentJobs', 'No recent jobs.')) + '</td></tr>');
    } else {
      list.forEach(function(job){
        const progress = jobProgress(job);
        const statusClass = job.status === 'completed' ? 'yo-safe' : (job.status === 'cancelled' || job.status === 'expired' ? 'yo-warning' : 'yo-review-badge');
        $body.append('<tr><td>' + escapeHtml(jobTypeLabel(job.type, (job.context || {}).operation)) + '</td><td><span class="yo-badge ' + statusClass + '">' + escapeHtml(jobStatusLabel(job.status)) + '</span></td><td>' + escapeHtml(job.processed || 0) + ' / ' + escapeHtml(job.total_known === false ? '?' : (job.total || 0)) + (job.total_known === false ? '' : ' (' + progress.toFixed(0) + '%)') + ' ' + escapeHtml(job.type === 'prune' ? t('jobFiles', 'files') : (job.type === 'regenerate' ? t('jobImages', 'images') : t('jobScanRecords', 'scan records'))) + '</td><td>' + escapeHtml(formatDate(job.updated_at)) + (' <button type="button" class="button-link yotm-health-view" data-token="' + escapeHtml(job.token) + '">' + escapeHtml(t('viewJob', 'View job')) + '</button>') + '</td></tr>');
      });
    }

    recentActiveJob = list.find(function(job){ return activeStatuses.includes(job.status); }) || null;
    if (!recentActiveJob) {
      $('#yotm_active_job').addClass('yo-hidden');
      return;
    }
    $('#yotm_active_job_title').text(t('activeJob', 'Active job') + ': ' + jobTypeLabel(recentActiveJob.type, (recentActiveJob.context || {}).operation));
    $('#yotm_active_job_meta').text(jobStatusLabel(recentActiveJob.status) + ' — ' + recentActiveJob.processed + ' / ' + recentActiveJob.total);
    $('#yotm_active_job').removeClass('yo-hidden');
  }

  function loadRecentJobs() {
    request('yotm_jobs_recent').done(function(response){
      if (response && response.success && response.data) {
        renderRecentJobs(response.data.jobs || []);
        resumeDiscoveredJob(recentActiveJob);
      }
    });
  }

  $('#yotm_recent_jobs_body').on('click', '.yotm-health-view', function(){
    if (window.YOTMResults) { window.YOTMResults.open($(this).attr('data-token')); }
  });

  $('#yotm_active_job_view').on('click', function(){
    if (recentActiveJob) {
      activateTab(tabForJob(recentActiveJob.type));
      $('#yotm_panel_' + tabForJob(recentActiveJob.type)).attr('tabindex', '-1').trigger('focus');
    }
  });

  function resumeDiscoveredJob(job) {
    if (!job || isJobStopping(job.token)) {
      return false;
    }
    return resumeWorkflow(job.type, job.token);
  }

  let started = false;

  function start() {
    if (started) {
      return;
    }
    started = true;

    const storedPrune = recallJob('prune');
    const storedRegen = recallJob('regenerate');
    const storedRecommend = recallJob('recommendation');
    if (storedPrune) {
      resumeWorkflow('prune', storedPrune);
    } else if (storedRegen) {
      resumeWorkflow('regenerate', storedRegen);
    }
    if (storedRecommend) {
      resumeWorkflow('recommendation', storedRecommend);
    }
    if (window.YOTM_RUN_REGENERATE_AFTER_SAVE && !storedPrune && !storedRegen) {
      activateTab('regenerate');
      window.setTimeout(function(){
        const regenerateWorkflow = workflows.regenerate;
        const destructiveActive = ['prune', 'regenerate'].some(function(type){
          const workflow = workflows[type];
          return workflow && typeof workflow.isActive === 'function' && workflow.isActive();
        });
        if (regenerateWorkflow && typeof regenerateWorkflow.prepare === 'function' && !destructiveActive) {
          regenerateWorkflow.prepare();
        }
      }, 250);
    }
    loadRecentJobs();
  }

  // Internal module seam for bundled admin modules; not a public extension API.
  window.YOTMAdmin = Object.freeze({
    activateTab: activateTab,
    cancelJob: cancelJob,
    errorDetails: errorDetails,
    escapeHtml: escapeHtml,
    forgetJob: forgetJob,
    formatBytes: formatBytes,
    formatDate: formatDate,
    formatTemplate: formatTemplate,
    getJobStatus: getJobStatus,
    htmlNotice: htmlNotice,
    isMissingJobStatus: isMissingJobStatus,
    isJobStopping: isJobStopping,
    stopPreparation: stopPreparation,
    stopJobMessage: stopJobMessage,
    jobProgress: jobProgress,
    loadRecentJobs: loadRecentJobs,
    recallJob: recallJob,
    registerWorkflow: registerWorkflow,
    resumeWorkflow: resumeWorkflow,
    rememberJob: rememberJob,
    request: request,
    responseError: responseError,
    resultActions: resultActions,
    t: t
  });
})(jQuery);
