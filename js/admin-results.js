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

  let reportOpen = false;
  let returnFocus = null;

  function text(key, fallback) { return esc(t(key, fallback)); }
  function statusLabel(status) {
    return {completed: t('statusCompleted', 'Completed'), cancelled: t('statusCancelled', 'Stopped'), expired: t('statusExpired', 'Expired'), running: t('statusRunning', 'Running'), scanning: t('statusScanning', 'Scanning'), awaiting_approval: t('statusAwaitingApproval', 'Awaiting review'), approved: t('statusApproved', 'Approved'), deleting: t('statusDeleting', 'Deleting'), done: t('statusCompleted', 'Completed'), failed: t('resultFailed', 'Failed'), skipped: t('resultSkipped', 'Skipped'), queued: t('resultQueued', 'Queued'), processing: t('processing', 'Processing…'), healthy: t('healthHealthy', 'Healthy'), missing: t('healthMissing', 'Missing'), blocked: t('healthBlocked', 'Blocked'), disabled: t('healthDisabled', 'Disabled'), unsupported: t('healthUnsupported', 'Unsupported'), not_needed: t('healthNotNeeded', 'Not needed')}[status] || status;
  }
  function badge(status) {
    const tone = {completed: 'yo-safe', done: 'yo-safe', healthy: 'yo-safe', failed: 'yo-danger', blocked: 'yo-danger', missing: 'yo-warning', cancelled: 'yo-warning', expired: 'yo-warning', skipped: 'yo-health-neutral'}[status] || 'yo-health-neutral';
    return '<span class="yo-badge ' + tone + '">' + esc(statusLabel(status)) + '</span>';
  }
  function backButton() {
    return '<button class="button-link yo-report-back" type="button" id="yotm_result_close"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' + text('resultBack', 'Back to tools') + '</button>';
  }
  function controls() {
    return '<div class="yo-report-actions"><button class="button" type="button" id="yotm_result_refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span>' + text('resultRefresh', 'Refresh results') + '</button><button class="button" type="button" id="yotm_result_export"><span class="dashicons dashicons-download" aria-hidden="true"></span>' + text('resultExport', 'Download JSON report') + '</button></div>';
  }
  function filterControl(data) {
    return '<label class="yo-report-filter" for="yotm_result_filter"><span>' + text('resultFilter', 'Show results') + '</span><select id="yotm_result_filter">' +
      [['', t('resultAll', 'All items')], ['unsuccessful', t('resultUnsuccessful', 'Unsuccessful items')], ['failed', t('resultFailed', 'Failed')], ['skipped', t('resultSkipped', 'Skipped')]].map(function(option){
        return '<option value="' + option[0] + '"' + (data.filter === option[0] ? ' selected' : '') + '>' + esc(option[1]) + '</option>';
      }).join('') + '</select></label>';
  }
  function metric(label, value, total, tone) {
    return '<div class="yo-result-metric ' + (tone || '') + '"><dt>' + esc(label.replace(/:$/, '')) + '</dt><dd>' + esc(value == null ? '—' : value) + (total === undefined ? '' : '<span> / ' + esc(total == null ? '?' : total) + '</span>') + '</dd></div>';
  }
  function scopeContent(context) {
    const sizes = Array.isArray(context.selected_sizes) ? context.selected_sizes : [];
    let html = '<div class="yo-report-scope-group"><span class="yo-report-label">' + text('scope', 'Scope:') + '</span><strong>' + esc(context.scope_label || context.scan_base_label || t('allMedia', 'All media')) + '</strong></div>';
    if (context.keep) {
      html += '<div class="yo-report-scope-group"><span class="yo-report-label">' + text('keep', 'KEEP:') + '</span><span>' + esc(context.keep.join(', ')) + '</span></div><div class="yo-report-scope-group"><span class="yo-report-label">' + text('deleteNotSelected', 'DELETE (not selected):') + '</span><span>' + esc((context.remove || []).join(', ')) + '</span></div>';
    } else {
      html += '<div class="yo-report-scope-group"><span class="yo-report-label">' + text('size', 'Size') + '</span><span class="yo-size-tags">' + (sizes.length ? sizes.map(function(size){ return '<span class="yo-size-tag">' + esc(size) + '</span>'; }).join('') : esc(t('resultDefaultSizes', 'Default size policy for this run'))) + '</span></div>';
    }
    return '<div class="yo-report-scope">' + html + '</div>';
  }
  function sizeReason(reason) {
    return {file_present: t('healthFilePresent', 'Thumbnail file is present.'), file_missing: t('healthFileMissing', 'Thumbnail file is missing.'), metadata_missing: t('healthMetadataMissing', 'Thumbnail metadata is missing.'), size_not_enabled: t('healthSizeNotEnabled', 'This thumbnail size is currently disabled.'), source_size_not_resizable: t('healthSourceTooSmall', 'This size is not needed for the source image dimensions.'), size_no_longer_registered: t('healthSizeUnregistered', 'This size is no longer registered; existing files were preserved.'), unsafe_thumbnail_path: t('healthUnsafePath', 'The thumbnail path could not be verified safely.'), invalid_size_definition: t('healthInvalidDefinition', 'This thumbnail size has an invalid definition.'), upscaled_size_definition: t('healthUpscaledDefinition', 'This thumbnail size would require enlarging the source image.')}[reason] || '';
  }
  function itemDetails(health) {
    if (!health.sizes || !Object.keys(health.sizes).length) { return ''; }
    return '<details class="yo-result-details"><summary>' + text('healthSizeDetails', 'Thumbnail size details') + ' (' + Object.keys(health.sizes).length + ')</summary><ul>' + Object.keys(health.sizes).map(function(slug){
      const detail = health.sizes[slug];
      return '<li><div><strong>' + esc(slug) + '</strong>' + badge(detail.status) + '</div><p>' + esc(detail.action || sizeReason(detail.reason)) + '</p></li>';
    }).join('') + '</ul></details>';
  }
  function itemRow(item, selection, operation) {
    const health = item.health || {};
    const title = item.title || item.path || '#' + item.attachment_id;
    const link = item.edit_url && /^https?:\/\//i.test(item.edit_url) ? '<a href="' + esc(item.edit_url) + '">' + esc(title) + '</a>' : esc(title);
    let identity = link;
    if (item.attachment_id) { identity += '<span class="yo-result-secondary">#' + esc(item.attachment_id) + '</span>'; }
    if (item.path && item.path !== title) { identity += '<code class="yo-result-path">' + esc(item.path) + '</code>'; }
    const mainStatus = item.status === 'failed' && health.status !== 'blocked' ? 'failed' : (health.status || item.status);
    let state = badge(mainStatus);
    if (health.status && item.status !== 'done') { state += '<span class="yo-result-secondary">' + esc(statusLabel(item.status)) + '</span>'; }
    if (health.status && item.status === 'done' && health.status !== 'healthy') { state += '<span class="yo-result-secondary">' + (operation === 'check' ? text('resultInspectionDone', 'Inspection complete') : text('statusCompleted', 'Completed')) + '</span>'; }
    let detail = '<p class="yo-result-reason">' + esc(item.error || health.message || '') + '</p>';
    if (item.action) { detail += '<p class="yo-result-action">' + esc(item.action) + '</p>'; }
    if ((item.scope_preserved_sizes || []).length) { detail += '<p class="yo-result-action">' + text('resultPreservedSizes', 'Preserved because current size policy excludes them:') + ' ' + esc(item.scope_preserved_sizes.join(', ')) + '</p>'; }
    detail += itemDetails(health);
    return '<tr>' + (selection ? '<td class="yo-result-check">' + (item.retryable ? '<input type="checkbox" class="yotm-result-select" aria-label="' + esc(t('resultSelect', 'Select for retry') + ': ' + title) + '" value="' + esc(item.key) + '"' + (selected.has(item.key) ? ' checked' : '') + '>' : '') + '</td>' : '') + '<th scope="row" class="yo-result-identity">' + identity + '</th><td class="yo-result-state">' + state + '</td><td class="yo-result-detail">' + detail + '</td></tr>';
  }
  function resultNotes(data) {
    const job = data.job;
    return '<details class="yo-report-notes"' + (!data.complete ? ' open' : '') + '><summary>' + text('resultNotes', 'Coverage, dates & retention') + '</summary><dl class="yo-report-dates"><div><dt>' + text('resultCreated', 'Started (UTC):') + '</dt><dd>' + esc(job.created_at) + '</dd></div><div><dt>' + text('resultUpdated', 'Updated (UTC):') + '</dt><dd>' + esc(job.updated_at) + '</dd></div><div><dt>' + text('resultExpires', 'Saved deadline (UTC):') + '</dt><dd>' + esc(job.expires_at) + '</dd></div></dl><ul>' + (data.limitations || []).map(function(note){ return '<li>' + esc(note) + '</li>'; }).join('') + '</ul></details>';
  }
  function render(data) {
    current = data;
    const job = data.job;
    const context = job.context || {};
    const operation = {check: t('jobThumbnailCheck', 'Check thumbnails'), repair: t('jobThumbnailRepair', 'Repair missing thumbnails'), force: t('runForceRegenerate', 'Force regeneration'), regenerate: t('jobRegenerate', 'Regenerate'), prune: t('jobPrune', 'Prune files'), recommendation: t('jobRecommendation', 'Recommendations')}[context.operation || (context.force_all ? 'force' : job.type)] || job.type;
    let html = backButton() + '<div class="yo-report-heading"><div><p class="yo-report-kicker">' + text('resultTitle', 'Saved job results') + '</p><h2 id="yotm_report_heading">' + esc(operation) + badge(job.status) + '</h2><p class="yo-report-meta">' + text('resultUpdated', 'Updated (UTC):') + ' ' + esc(job.updated_at) + '<span class="yo-report-retention">' + text('resultSavedUntil', 'Saved until (UTC):') + ' ' + esc(job.expires_at) + '</span></p></div>' + controls() + '</div>';
    html += scopeContent(context);
    html += '<dl class="yo-report-metrics">' + metric(job.type === 'prune' ? t('resultFilesProcessed', 'Files processed') : (job.type === 'recommendation' ? t('resultRecordsScanned', 'Records scanned') : t('resultImagesProcessed', 'Images processed')), job.processed, job.total_known === false ? null : job.total);
    if (job.type !== 'recommendation') {
      html += metric(context.operation === 'check' ? t('resultInspected', 'Inspected successfully:') : t('resultSucceeded', 'Successful:'), job.succeeded) + metric(t('skipped', 'Skipped:'), job.skipped) + metric(t('failed', 'Failed:'), job.failed, undefined, job.failed ? 'has-errors' : '');
    }
    html += '</dl>';
    if (job.type === 'prune') { html += '<p class="yo-report-byte-summary">' + text('resultCandidateBytes', 'Candidate bytes (estimate):') + ' <strong>' + esc(core.formatBytes(context.estimated_bytes || 0)) + '</strong> · ' + text('resultFreedBytes', 'Recorded bytes freed:') + ' <strong>' + esc(job.bytes_human) + '</strong></p>'; }
    if (!data.complete) { html += '<div class="yo-report-incomplete"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' + text('resultPartial', 'This saved result is partial. Refresh to load the latest observations; opening it starts no processing.') + '</p></div>'; }
    if (job.type === 'recommendation' && context.result && window.YOTMRecommendationReport) { html += window.YOTMRecommendationReport(context.result); }
    if (job.type !== 'recommendation') {
      const selection = data.can_retry && (data.items || []).some(function(item){ return item.retryable; });
      html += '<div class="yo-result-toolbar">' + filterControl(data) + '<span class="yo-result-count">' + esc(data.total) + ' ' + text('resultMatching', 'matching items') + '</span></div>';
      if ((data.items || []).length) {
        html += '<div class="yo-table-scroll"><table class="widefat yo-results-table' + (selection ? ' has-selection' : '') + '"><thead><tr>' + (selection ? '<td class="yo-result-check"><span class="screen-reader-text">' + text('resultSelect', 'Select for retry') + '</span></td>' : '') + '<th scope="col" class="yo-result-identity">' + text('healthTitle', 'Image') + ' / ' + text('resultPath', 'File') + '</th><th scope="col" class="yo-result-state">' + text('status', 'Status') + '</th><th scope="col">' + text('resultReasonAction', 'Outcome & next step') + '</th></tr></thead><tbody>' + data.items.map(function(item){ return itemRow(item, selection, context.operation); }).join('') + '</tbody></table></div>';
      } else {
        html += '<div class="yo-result-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3>' + text('resultEmpty', 'No items in this view') + '</h3><p>' + (data.filter ? text('resultEmptyFilter', 'Choose another filter to view the other saved results.') : text('resultEmptyPending', 'No item details are available yet. Review the summary and coverage notes.')) + '</p></div>';
      }
      if (data.pages > 1) {
        html += '<nav class="yo-result-pagination" aria-label="' + text('resultPages', 'Result pages') + '"><span>' + esc(data.page) + ' / ' + esc(data.pages) + '</span>';
        if (data.page > 1) { html += '<button type="button" class="button yotm-result-page" data-page="' + (data.page - 1) + '">' + text('previousPage', 'Previous') + '</button>'; }
        if (data.page < data.pages) { html += '<button type="button" class="button yotm-result-page" data-page="' + (data.page + 1) + '">' + text('nextPage', 'Next') + '</button>'; }
        html += '</nav>';
      }
      if (data.can_retry && (selection || selected.size)) {
        html += '<div class="yo-result-selection"><div><strong id="yotm_result_selection_count" aria-live="polite">' + selected.size + ' / 100 ' + text('resultSelected', 'selected') + '</strong><p>' + text('resultRetryHint', 'A retry creates a new job. Completed items stay unchanged.') + '</p></div><button type="button" class="button button-primary" id="yotm_result_retry"' + (selected.size ? '' : ' disabled') + '>' + text('resultRetry', 'Retry selected items in a new job') + '</button></div>';
      }
    }
    if (['running', 'scanning', 'approved', 'deleting', 'awaiting_approval'].includes(job.status) && job.expires_at >= data.read_at) { html += '<p><button type="button" class="button" id="yotm_result_resume">' + text('resultResume', 'Continue active job') + '</button></p>'; }
    if (data.optimizer_url && /^https?:\/\//i.test(data.optimizer_url)) { html += '<p><a class="button" href="' + esc(data.optimizer_url) + '">' + text('optimizerHandoff', 'Create WebP/AVIF for completed thumbnails') + '</a></p><p class="description">' + text('optimizerHandoffHint', 'Review the exact generated files in Media Optimizer before running. Original images and other thumbnail sizes are excluded.') + '</p>'; }
    html += resultNotes(data) + '<div id="yotm_result_message" class="yo-report-message" role="status"></div>';
    $report.html(html).removeClass('yo-hidden');
  }
  function open(token, page, filter) {
    const requestId = ++generation;
    if (!reportOpen) { returnFocus = document.activeElement; }
    reportOpen = true;
    $('.yotm-wrap').addClass('yo-report-open');
    if (!current || current.job.token !== token) { selected = new Set(); }
    $report.removeClass('yo-hidden').html(backButton() + '<h2 id="yotm_report_heading">' + text('resultTitle', 'Saved job results') + '</h2>' + '<p role="status">' + text('healthLoading', 'Loading thumbnail results…') + '</p>').trigger('focus');
    core.request('yotm_job_results', {token: token, page: page || 1, filter: filter || ''}).done(function(response){
      if (requestId !== generation) { return; }
      if (!response || !response.success || !response.data) { $report.html(backButton() + '<h2 id="yotm_report_heading">' + text('resultTitle', 'Saved job results') + '</h2>' + core.htmlNotice('notice-warning', core.responseError(response, t('resultUnavailable', 'This saved result is unavailable. Run a new check or scan.')))); return; }
      render(response.data);
    }).fail(function(xhr){
      if (requestId === generation) { $report.html(backButton() + '<h2 id="yotm_report_heading">' + text('resultTitle', 'Saved job results') + '</h2>' + core.htmlNotice('notice-error', core.responseError(xhr.responseJSON, t('healthLoadFailed', 'Could not load thumbnail results.')))); }
    });
  }
  function closeReport() {
    ++generation;
    reportOpen = false;
    $report.addClass('yo-hidden');
    $('.yotm-wrap').removeClass('yo-report-open');
    if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') { returnFocus.focus(); } else { $('#yotm_recent_jobs > summary').trigger('focus'); }
  }
  function resumeFromReport(type, token) {
    closeReport();
    core.activateTab(type === 'recommendation' ? 'recommendations' : type);
    core.resumeWorkflow(type, token);
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
    $('#yotm_result_retry').prop('disabled', !selected.size);
    $('#yotm_result_selection_count').text(selected.size + ' / 100 ' + t('resultSelected', 'selected'));
  });
  $report.on('click', '#yotm_result_retry', function(){
    if (!current || !selected.size) { return; }
    const source = current.job;
    $('#yotm_result_retry').prop('disabled', true);
    core.request('yotm_job_retry', {token: source.token, keys: Array.from(selected)}).done(function(response){
      if (!response || !response.success || !response.data) { $('#yotm_result_message').html(core.htmlNotice('notice-error', core.responseError(response, t('resultRetryFailed', 'Could not prepare retry.')))); $('#yotm_result_retry').prop('disabled', false); return; }
      const data = response.data;
      core.rememberJob(source.type, data.token);
      resumeFromReport(source.type, data.token);
      selected = new Set();
      core.loadRecentJobs();
    }).fail(function(xhr){ $('#yotm_result_message').html(core.htmlNotice('notice-error', core.responseError(xhr.responseJSON, t('resultRetryFailed', 'Could not prepare retry.')))); $('#yotm_result_retry').prop('disabled', false); });
  });
  $report.on('click', '#yotm_result_resume', function(){ if (current) { resumeFromReport(current.job.type, current.job.token); } });
  $report.on('click', '#yotm_result_export', exportReport);
  $report.on('click', '#yotm_result_refresh', function(){ if (current) { open(current.job.token, current.page, current.filter); } });
  $report.on('click', '#yotm_result_close', closeReport);
  $('#yotm_regen_results, #yotm_recommend_results, #yotm_prune_results').on('click', '.yotm-open-report', function(){ open($(this).attr('data-token')); });
  window.YOTMResults = Object.freeze({open: open});
  if (window.YOTM && window.YOTM.resultToken) { open(window.YOTM.resultToken); }
})(jQuery, window.YOTMAdmin);
