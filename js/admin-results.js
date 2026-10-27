(function($, core){
  'use strict';
  if (!core) { return; }
  const esc = core.escapeHtml;
  const t = core.t;
  const $report = $('#yotm_job_report');
  let current = null;
  let generation = 0;
  let selected = new Set();
  let exporting = false;

  function text(key, fallback) { return esc(t(key, fallback)); }
  function statusLabel(status) {
    return {completed: t('statusCompleted', 'Completed'), cancelled: t('statusCancelled', 'Stopped'), expired: t('statusExpired', 'Expired'), running: t('statusRunning', 'Running'), scanning: t('statusScanning', 'Scanning'), awaiting_approval: t('statusAwaitingApproval', 'Awaiting review'), approved: t('statusApproved', 'Approved'), deleting: t('statusDeleting', 'Deleting'), done: t('statusCompleted', 'Completed'), failed: t('resultFailed', 'Failed'), skipped: t('resultSkipped', 'Skipped'), queued: t('resultQueued', 'Queued'), processing: t('processing', 'Processing…'), healthy: t('healthHealthy', 'Healthy'), missing: t('healthMissing', 'Missing'), blocked: t('healthBlocked', 'Blocked'), disabled: t('healthDisabled', 'Disabled'), unsupported: t('healthUnsupported', 'Unsupported'), not_needed: t('healthNotNeeded', 'Not needed')}[status] || status;
  }
  function controls(data) {
    const filter = data.job.type === 'recommendation' ? '' : '<label>' + text('resultFilter', 'Show results') + ' <select id="yotm_result_filter">' +
      [['', t('resultAll', 'All items')], ['unsuccessful', t('resultUnsuccessful', 'Unsuccessful items')], ['failed', t('resultFailed', 'Failed')], ['skipped', t('resultSkipped', 'Skipped')]].map(function(option){
        return '<option value="' + option[0] + '"' + (data.filter === option[0] ? ' selected' : '') + '>' + esc(option[1]) + '</option>';
      }).join('') + '</select></label> ';
    return '<p>' + filter + '<button class="button" type="button" id="yotm_result_export">' + text('resultExport', 'Download JSON report') + '</button> <button class="button" type="button" id="yotm_result_refresh">' + text('resultRefresh', 'Refresh results') + '</button> <button class="button" type="button" id="yotm_result_close">' + text('resultClose', 'Close results') + '</button></p>';
  }
  function render(data) {
    current = data;
    const job = data.job;
    const context = job.context || {};
    const operation = {check: t('jobThumbnailCheck', 'Check thumbnails'), repair: t('jobThumbnailRepair', 'Repair missing thumbnails'), force: t('runForceRegenerate', 'Force regeneration'), regenerate: t('jobRegenerate', 'Regenerate'), prune: t('jobPrune', 'Prune files'), recommendation: t('jobRecommendation', 'Recommendations')}[context.operation || (context.force_all ? 'force' : job.type)] || job.type;
    const scope = context.scope_label || context.scan_base_label || t('allMedia', 'All media');
    const sizes = Array.isArray(context.selected_sizes) ? context.selected_sizes.join(', ') : t('resultDefaultSizes', 'Default size policy for this run');
    let html = '<h2>' + text('resultTitle', 'Saved job results') + '</h2>' + controls(data);
    html += '<p><strong>' + esc(operation) + ' — ' + esc(statusLabel(job.status)) + '</strong><br>' + text('scope', 'Scope:') + ' ' + esc(scope) + '<br>' + text('size', 'Size') + ': ' + esc(sizes) + '</p>';
    if (context.keep) { html += '<p>' + text('keep', 'KEEP:') + ' ' + esc(context.keep.join(', ')) + '<br>' + text('deleteNotSelected', 'DELETE (not selected):') + ' ' + esc((context.remove || []).join(', ')) + '</p>'; }
    const count = function(value){ return esc(value == null ? '?' : value); };
    html += '<p>' + text('processed', 'Processed:') + ' ' + count(job.processed) + ' / ' + count(job.total_known === false ? null : job.total) + ' ' + esc({files: t('jobFiles', 'files'), images: t('jobImages', 'images'), scan: t('jobScanRecords', 'scan records')}[data.unit] || data.unit) +
      (job.type === 'recommendation' ? '' : ' · ' + (context.operation === 'check' ? text('resultInspected', 'Inspected successfully:') : text('resultSucceeded', 'Successful:')) + ' ' + count(job.succeeded) + ' · ' + text('failed', 'Failed:') + ' ' + count(job.failed) + ' · ' + text('skipped', 'Skipped:') + ' ' + count(job.skipped)) + '</p>';
    if (job.type === 'prune') { html += '<p>' + text('resultCandidateBytes', 'Candidate bytes (estimate):') + ' ' + esc(core.formatBytes(context.estimated_bytes || 0)) + ' · ' + text('resultFreedBytes', 'Recorded bytes freed:') + ' ' + esc(job.bytes_human) + '</p>'; }
    html += '<p>' + text('resultCreated', 'Started (UTC):') + ' ' + esc(job.created_at) + ' · ' + text('resultUpdated', 'Updated (UTC):') + ' ' + esc(job.updated_at) + ' · ' + text('resultExpires', 'Saved deadline (UTC):') + ' ' + esc(job.expires_at) + '</p>';
    html += '<ul>' + (data.limitations || []).map(function(note){ return '<li>' + esc(note) + '</li>'; }).join('') + '</ul>';
    if (job.type === 'recommendation' && context.result && window.YOTMRecommendationReport) { html += window.YOTMRecommendationReport(context.result); }
    if (job.type === 'recommendation') {
      html += '<div id="yotm_result_message" role="status"></div>';
      $report.html(html).removeClass('yo-hidden');
      return;
    }
    html += '<p>' + text('resultMatchCount', 'Items matching this filter:') + ' ' + count(data.total) + '</p>';
    html += '<div class="yo-table-scroll"><table class="widefat striped"><thead><tr><th>' + text('resultSelect', 'Select for retry') + '</th><th>' + text('healthTitle', 'Image') + ' / ' + text('resultPath', 'File') + '</th><th>' + text('status', 'Status') + '</th><th>' + text('reason', 'Reason') + ' / ' + text('healthAction', 'Suggested action') + '</th></tr></thead><tbody>';
    (data.items || []).forEach(function(item){
      const health = item.health || {};
      const preserved = (item.scope_preserved_sizes || []).length ? '<p>' + text('resultPreservedSizes', 'Preserved because current size policy excludes them:') + ' ' + esc(item.scope_preserved_sizes.join(', ')) + '</p>' : '';
      const title = item.title || item.path || '#' + item.attachment_id;
      const link = item.edit_url && /^https?:\/\//i.test(item.edit_url) ? '<a href="' + esc(item.edit_url) + '">' + esc(title) + '</a>' : esc(title);
      html += '<tr><td>' + (item.retryable ? '<input type="checkbox" class="yotm-result-select" aria-label="' + esc(t('resultSelect', 'Select for retry') + ': ' + title) + '" value="' + esc(item.key) + '"' + (selected.has(item.key) ? ' checked' : '') + '>' : '') + '</td><td>' + link + (item.path ? '<br><code>' + esc(item.path) + '</code>' : '') + '</td><td>' + esc(statusLabel(item.status)) + (health.status ? ' / ' + esc(statusLabel(health.status)) : '') + '</td><td>' + esc(item.error || health.message || '') + preserved + (item.action ? '<p>' + esc(item.action) + '</p>' : '') + (health.sizes && Object.keys(health.sizes).length ? '<details><summary>' + text('healthSizeDetails', 'Thumbnail size details') + '</summary><ul>' + Object.keys(health.sizes).map(function(slug){ const detail = health.sizes[slug]; return '<li><strong>' + esc(slug) + '</strong>: ' + esc(statusLabel(detail.status)) + ' — ' + esc(detail.reason) + (detail.action ? '<p>' + esc(detail.action) + '</p>' : '') + '</li>'; }).join('') + '</ul></details>' : '') + '</td></tr>';
    });
    html += '</tbody></table></div><p>';
    if (data.page > 1) { html += '<button type="button" class="button yotm-result-page" data-page="' + (data.page - 1) + '">' + text('previousPage', 'Previous') + '</button> '; }
    html += esc(data.page) + ' / ' + esc(data.pages);
    if (data.page < data.pages) { html += ' <button type="button" class="button yotm-result-page" data-page="' + (data.page + 1) + '">' + text('nextPage', 'Next') + '</button>'; }
    html += '</p>';
    if (data.can_retry) { html += '<p><button type="button" class="button button-primary" id="yotm_result_retry"' + (selected.size ? '' : ' disabled') + '>' + text('resultRetry', 'Retry selected items in a new job') + ' (' + selected.size + '/100)</button></p>'; }
    if (['running', 'scanning', 'approved', 'deleting', 'awaiting_approval'].includes(job.status) && job.expires_at >= data.read_at) { html += '<p><button type="button" class="button" id="yotm_result_resume">' + text('resultResume', 'Continue active job') + '</button></p>'; }
    html += '<div id="yotm_result_message" role="status"></div>';
    $report.html(html).removeClass('yo-hidden');
  }
  function open(token, page, filter) {
    const requestId = ++generation;
    if (!current || current.job.token !== token) { selected = new Set(); }
    $report.removeClass('yo-hidden').html(core.htmlNotice('notice-info', t('healthLoading', 'Loading thumbnail results…'))).trigger('focus');
    core.request('yotm_job_results', {token: token, page: page || 1, filter: filter || ''}).done(function(response){
      if (requestId !== generation) { return; }
      if (!response || !response.success || !response.data) { $report.html(core.htmlNotice('notice-warning', core.responseError(response, t('resultUnavailable', 'This saved result is unavailable. Run a new check or scan.')))); return; }
      render(response.data);
    }).fail(function(xhr){
      if (requestId === generation) { $report.html(core.htmlNotice('notice-error', core.responseError(xhr.responseJSON, t('healthLoadFailed', 'Could not load thumbnail results.')))); }
    });
  }
  function exportReport() {
    if (!current || exporting) { return; }
    exporting = true;
    const snapshot = current;
    const output = Object.assign({}, snapshot, {items: [], filter: '', exported_at: new Date().toISOString(), export_complete: false});
    const token = snapshot.job.token;
    $('#yotm_result_export').prop('disabled', true);
    const fail = function(response){
      exporting = false;
      $('#yotm_result_export').prop('disabled', false);
      $('#yotm_result_message').html(core.htmlNotice('notice-error', core.responseError(response, t('resultExportFailed', 'Export failed. Saved results are unchanged; reopen them and try again.'))));
    };
    function fetchPage(page) {
      core.request('yotm_job_results', {token: token, page: page}).done(function(response){
        if (!response || !response.success || !response.data) { fail(response); return; }
        const data = response.data;
        if (page === 1) {
          Object.assign(output, data, {items: [], exported_at: new Date().toISOString(), export_complete: false});
        } else if (data.job.updated_at !== output.job.updated_at) { output.complete = false; }
        output.items = output.items.concat(data.items || []);
        if (page < data.pages && output.items.length < 10000) { fetchPage(page + 1); return; }
        output.export_complete = page >= data.pages;
        if (!output.export_complete) { output.limitations = output.limitations.concat(t('resultExportLimit', 'Export contains the first 10,000 items. The saved summary covers the full job; use the on-screen filters to inspect other items.')); }
        output.limitations = output.limitations.concat(t('resultExportSnapshot', 'Export reads saved pages in order. An active job can change while it is being exported; use a terminal job for a stable result.'));
        const blob = new window.Blob([JSON.stringify(output, null, 2)], {type: 'application/json;charset=utf-8'});
        const url = window.URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'thumbnail-manager-' + token + '.json';
        anchor.click();
        window.setTimeout(function(){ window.URL.revokeObjectURL(url); }, 1000);
        exporting = false;
        $('#yotm_result_export').prop('disabled', false);
        $('#yotm_result_message').text(t('resultExportDone', 'Report downloaded.'));
      }).fail(function(xhr){ fail(xhr.responseJSON); });
    }
    fetchPage(1);
  }
  $report.on('change', '#yotm_result_filter', function(){ selected = new Set(); open(current.job.token, 1, $(this).val()); });
  $report.on('click', '.yotm-result-page', function(){ open(current.job.token, parseInt($(this).attr('data-page'), 10), current.filter); });
  $report.on('change', '.yotm-result-select', function(){
    const key = $(this).val();
    if ($(this).prop('checked')) {
      if (selected.size >= 100) { $(this).prop('checked', false); return; }
      selected.add(key);
    } else { selected.delete(key); }
    $('#yotm_result_retry').prop('disabled', !selected.size).text(t('resultRetry', 'Retry selected items in a new job') + ' (' + selected.size + '/100)');
  });
  $report.on('click', '#yotm_result_retry', function(){
    if (!current || !selected.size) { return; }
    const source = current.job;
    $('#yotm_result_retry').prop('disabled', true);
    core.request('yotm_job_retry', {token: source.token, keys: Array.from(selected)}).done(function(response){
      if (!response || !response.success || !response.data) { $('#yotm_result_message').html(core.htmlNotice('notice-error', core.responseError(response, t('resultRetryFailed', 'Could not prepare retry.')))); $('#yotm_result_retry').prop('disabled', false); return; }
      const data = response.data;
      core.rememberJob(source.type, data.token);
      core.resumeWorkflow(source.type, data.token);
      selected = new Set();
      core.loadRecentJobs();
      open(data.token);
    }).fail(function(xhr){ $('#yotm_result_message').html(core.htmlNotice('notice-error', core.responseError(xhr.responseJSON, t('resultRetryFailed', 'Could not prepare retry.')))); $('#yotm_result_retry').prop('disabled', false); });
  });
  $report.on('click', '#yotm_result_resume', function(){ if (current) { core.resumeWorkflow(current.job.type, current.job.token); } });
  $report.on('click', '#yotm_result_export', exportReport);
  $report.on('click', '#yotm_result_refresh', function(){ if (current) { open(current.job.token, current.page, current.filter); } });
  $report.on('click', '#yotm_result_close', function(){ ++generation; $report.addClass('yo-hidden'); });
  $('#yotm_regen_results, #yotm_recommend_results, #yotm_prune_results').on('click', '.yotm-open-report', function(){ open($(this).attr('data-token')); });
  window.YOTMResults = Object.freeze({open: open});
})(jQuery, window.YOTMAdmin);
