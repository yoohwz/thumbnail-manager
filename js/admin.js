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

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function responseError(response, fallback) {
    return response && response.data && response.data.msg ? response.data.msg : fallback;
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

  function cancelJob(token) {
    return request('yotm_job_cancel', {token: token});
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
  }

  function resumeWorkflow(type, token) {
    const workflow = workflows[type];
    if (!token || !workflow || (typeof workflow.isActive === 'function' && workflow.isActive())) {
      return false;
    }
    workflow.resume(token);
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

  function jobTypeLabel(type) {
    return {
      prune: t('jobPrune', 'Prune files'),
      regenerate: t('jobRegenerate', 'Regenerate'),
      recommendation: t('jobRecommendation', 'Recommendations')
    }[type] || type;
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
        $body.append('<tr><td>' + escapeHtml(jobTypeLabel(job.type)) + '</td><td><span class="yo-badge ' + statusClass + '">' + escapeHtml(jobStatusLabel(job.status)) + '</span></td><td>' + escapeHtml(job.processed || 0) + ' / ' + escapeHtml(job.total || 0) + ' (' + progress.toFixed(0) + '%)</td><td>' + escapeHtml(formatDate(job.updated_at)) + '</td></tr>');
      });
    }

    recentActiveJob = list.find(function(job){ return activeStatuses.includes(job.status); }) || null;
    if (!recentActiveJob) {
      $('#yotm_active_job').addClass('yo-hidden');
      return;
    }
    $('#yotm_active_job_title').text(t('activeJob', 'Active job') + ': ' + jobTypeLabel(recentActiveJob.type));
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

  $('#yotm_active_job_view').on('click', function(){
    if (recentActiveJob) {
      activateTab(tabForJob(recentActiveJob.type));
      $('#yotm_panel_' + tabForJob(recentActiveJob.type)).attr('tabindex', '-1').trigger('focus');
    }
  });

  // Prune: configure -> scan -> review -> approve -> delete -> complete.
  let pruneToken = '';
  let pruneManifest = '';
  let pruneTotal = 0;
  let pruneRunning = false;
  let pruneReviewData = null;
  let manifestPage = 1;
  let manifestPages = 1;
  let manifestTimer = null;
	let pruneControlsLocked = false;

  const $pruneProg = $('#yotm_progress');
  const $pruneBar = $('#yotm_progress .bar');
  const $pruneStat = $('#yotm_status');
  const $pruneResults = $('#yotm_results');
  const $pruneRun = $('#yotm_run');
  const $pruneCancel = $('#yotm_cancel');
  const $pruneReview = $('#yotm_review_panel');
  const $pruneApprove = $('#yotm_approve_delete');

  function setPruneStep(step) {
    const order = ['configure', 'scanning', 'review', 'deleting', 'completed'];
    const current = order.indexOf(step);
    $('#yotm_prune_steps li').each(function(){
      const index = order.indexOf($(this).data('step'));
      $(this).toggleClass('is-complete', index < current).toggleClass('is-active', index === current);
      if (index === current) {
        $(this).attr('aria-current', 'step');
      } else {
        $(this).removeAttr('aria-current');
      }
    });
  }

  function lockPruneControls(locked) {
	pruneControlsLocked = !!locked;
    $('.yotm_keep, #yotm_discover_orphans, input[name="yotm_prune_scope"], #yotm_subpath_search, #yotm_subpath_select_visible, #yotm_subpath_clear').prop('disabled', pruneControlsLocked);
	refreshSubpathHierarchy();
  }

  function pruneProgress(percent, label, indeterminate) {
    const value = Math.max(0, Math.min(100, parseFloat(percent) || 0));
    $pruneProg.show().toggleClass('indeterminate', !!indeterminate).attr('aria-valuenow', value.toFixed(0));
    $pruneProg.attr('aria-busy', indeterminate ? 'true' : 'false');
    $pruneBar.css('width', Math.max(1, value) + '%');
    $pruneStat.show().text(label || value.toFixed(1) + '%');
  }

  function clearPruneJob(options) {
    const opts = options || {};
    pruneRunning = false;
    if (!opts.preserveJob) {
      pruneToken = '';
      pruneManifest = '';
      pruneTotal = 0;
      pruneReviewData = null;
      forgetJob('prune');
      lockPruneControls(false);
      $pruneCancel.addClass('yo-hidden').prop('disabled', false);
    } else {
      lockPruneControls(true);
      $pruneCancel.removeClass('yo-hidden').prop('disabled', false);
    }
    $pruneRun.prop('disabled', !!opts.preserveJob);
    if (!opts.keepReview) {
      $pruneReview.addClass('yo-hidden');
    }
    if (!opts.keepProgress) {
      $pruneProg.hide().removeClass('indeterminate').attr({'aria-valuenow': '0', 'aria-busy': 'false'});
      $pruneBar.css('width', '0');
      $pruneStat.hide().text('');
    }
    if (!opts.keepResults) {
      $pruneResults.empty();
    }
  }

  function gatherKeep() {
    return $('.yotm_keep:checked').map(function(){ return this.value; }).get();
  }

	function gatherPruneSubpaths() {
		if ($('input[name="yotm_prune_scope"]:checked').val() !== 'selected') {
			return [];
		}

		const selected = $('.yotm_subpath_option:checked').map(function(){ return this.value; }).get();
		selected.sort(function(left, right){
			const leftDepth = (left.match(/\//g) || []).length;
			const rightDepth = (right.match(/\//g) || []).length;
			return leftDepth === rightDepth ? left.localeCompare(right) : leftDepth - rightDepth;
		});

		return selected.filter(function(path, index, paths){
			return !paths.slice(0, index).some(function(parent){ return path.indexOf(parent + '/') === 0; });
		});
	}

	function refreshSubpathHierarchy() {
		$('.yotm_subpath_option[data-ancestor]').each(function(){
			const ancestor = $(this).data('ancestor');
			const covered = $('.yotm_subpath_option[data-kind="parent"][value="' + ancestor + '"]').is(':checked');
			$(this).prop('disabled', pruneControlsLocked || covered).closest('.yo-subpath-option').toggleClass('is-covered', covered);
		});
		$('.yotm_subpath_option[data-kind="parent"]').prop('disabled', pruneControlsLocked);
		$('#yotm_subpath_chips button').prop('disabled', pruneControlsLocked);
	}

	function updateSubpathSelection() {
		const paths = gatherPruneSubpaths();
		let label = t('noFolderSelected', 'Choose at least one folder or use All uploads.');
		if (paths.length === 1) {
			label = t('oneFolderSelected', '1 folder selected');
		} else if (paths.length > 1) {
			label = String(t('foldersSelected', '%s folders selected')).replace('%s', paths.length);
		}

		$('#yotm_subpath_selection_count').text(label);
		const chips = paths.map(function(path){
			return '<button type="button" class="yo-subpath-chip" data-path="' + escapeHtml(path) + '" aria-label="' + escapeHtml(t('removeFolder', 'Remove folder') + ' uploads/' + path) + '"><span>uploads/' + escapeHtml(path) + '/</span><span aria-hidden="true">×</span></button>';
		});
		$('#yotm_subpath_chips').html(chips.join(''));
		refreshSubpathHierarchy();
	}

	function toggleSubpathPicker() {
		const selectedMode = $('input[name="yotm_prune_scope"]:checked').val() === 'selected';
		$('#yotm_subpath_picker').toggleClass('yo-hidden', !selectedMode);
		if (selectedMode) {
			updateSubpathSelection();
		}
	}

	function filterSubpathOptions() {
		const term = String($('#yotm_subpath_search').val() || '').trim().toLowerCase();
		let visibleGroups = 0;
		$('.yo-subpath-group').each(function(){
			const $group = $(this);
			let visibleOptions = 0;
			$group.find('.yo-subpath-option').each(function(){
				const matches = !term || String($(this).data('search') || '').indexOf(term) !== -1;
				$(this).toggleClass('yo-filtered-out', !matches);
				if (matches) {
					visibleOptions += 1;
				}
			});
			$group.toggleClass('yo-filtered-out', visibleOptions === 0).prop('open', !!term && visibleOptions > 0);
			if (visibleOptions > 0) {
				visibleGroups += 1;
			}
		});
		$('#yotm_subpath_no_results').toggleClass('yo-hidden', visibleGroups > 0);
	}

	$('input[name="yotm_prune_scope"]').on('change', toggleSubpathPicker);
	$('#yotm_subpath_search').on('input', filterSubpathOptions);
	$(document).on('change', '.yotm_subpath_option', function(){
		if ($(this).data('kind') === 'parent' && this.checked) {
			$('.yotm_subpath_option[data-ancestor="' + this.value + '"]').prop('checked', false);
		}
		refreshSubpathHierarchy();
		updateSubpathSelection();
	});
	$('#yotm_subpath_select_visible').on('click', function(){
		$('.yo-subpath-group:not(.yo-filtered-out)').each(function(){
			$(this).find('.yo-subpath-option:not(.yo-filtered-out) .yotm_subpath_option').prop('checked', true);
		});
		$('.yotm_subpath_option[data-kind="parent"]:checked').each(function(){
			$('.yotm_subpath_option[data-ancestor="' + this.value + '"]').prop('checked', false);
		});
		updateSubpathSelection();
	});
	$('#yotm_subpath_clear').on('click', function(){
		$('.yotm_subpath_option').prop('checked', false);
		updateSubpathSelection();
	});
	$(document).on('click', '.yo-subpath-chip', function(){
		$('.yotm_subpath_option[value="' + $(this).data('path') + '"]').prop('checked', false);
		updateSubpathSelection();
	});

  function renderOrphanSummary(summary) {
    if (!summary) {
      return '';
    }
    const found = summary.found ? Object.keys(summary.found).length : 0;
    const marked = Array.isArray(summary.delete) ? summary.delete.length : 0;
    if (!found && !marked && !summary.unmapped && !summary.skipped_original && !summary.protected_sources && !summary.source_errors && !summary.unverified_sidecars && !summary.ambiguous_siblings) {
      return '';
    }
    return '<div class="notice notice-info inline"><p><strong>' + escapeHtml(t('orphanDiscovery', 'Orphan discovery:')) + '</strong> ' + found + ' ' + escapeHtml(t('distinctDimsFound', 'distinct dimensions found.')) + '<br><strong>' + escapeHtml(t('metadataOrphanDimsDelete', 'Metadata orphan dimensions marked for deletion:')) + '</strong> ' + marked + '<br><strong>' + escapeHtml(t('originalFilesProtected', 'Original files protected:')) + '</strong> ' + escapeHtml(summary.skipped_original || 0) + '<br><strong>' + escapeHtml(t('protectedSources', 'Authoritative source paths protected:')) + '</strong> ' + escapeHtml(summary.protected_sources || 0) + '<br><strong>' + escapeHtml(t('sourceErrors', 'Indeterminate source checks preserved:')) + '</strong> ' + escapeHtml(summary.source_errors || 0) + '<br><strong>' + escapeHtml(t('unverifiedSidecars', 'Unverified format sidecars preserved:')) + '</strong> ' + escapeHtml(summary.unverified_sidecars || 0) + '<br><strong>' + escapeHtml(t('ambiguousSiblings', 'Ambiguous sibling files preserved:')) + '</strong> ' + escapeHtml(summary.ambiguous_siblings || 0) + '<br><strong>' + escapeHtml(t('unmappedDiskSkipped', 'Unmapped disk candidates skipped:')) + '</strong> ' + escapeHtml(Math.max(summary.unmapped || 0, summary.unmapped_skipped || 0)) + '</p></div>';
  }

  function loadManifest(page) {
    if (!pruneToken) {
      return;
    }
    $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoading', 'Loading manifest…')) + '</td></tr>');
    $.post(ajaxurl, {
      action: 'yotm_job_items',
      nonce: nonce,
      token: pruneToken,
      page: page || 1,
      per_page: 25,
      search: $('#yotm_manifest_search').val() || ''
    }).done(function(response){
      if (!response || !response.success || !response.data) {
        $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoadFailed', 'Could not load the manifest.')) + '</td></tr>');
        return;
      }
      const data = response.data;
      manifestPage = parseInt(data.page || 1, 10);
      manifestPages = parseInt(data.pages || 1, 10);
      const $body = $('#yotm_manifest_body').empty();
      if (!Array.isArray(data.items) || !data.items.length) {
        $body.append('<tr><td colspan="4">' + escapeHtml(t('manifestEmpty', 'No manifest items match this filter.')) + '</td></tr>');
      } else {
        data.items.forEach(function(item){
          const proof = Array.isArray(item.ownership_evidence) && item.ownership_evidence.length ? item.ownership_evidence[0] : {};
          const attachmentId = proof.attachment_id || item.attachment_id;
          const attachment = attachmentId ? '#' + attachmentId : '—';
          const evidence = attachmentId ? [t('attachmentMetadata', 'Attachment metadata'), '#' + attachmentId, proof.size || item.size, proof.filename].filter(Boolean).join(' / ') : '—';
          $body.append('<tr><td><code>' + escapeHtml(item.path || t('unknownPath', 'Unknown item')) + '</code></td><td>' + escapeHtml(attachment) + '</td><td>' + escapeHtml(evidence) + '</td><td>' + escapeHtml(formatBytes(item.estimated_bytes || item.bytes)) + '</td></tr>');
        });
      }
      $('#yotm_manifest_count').text(data.total + ' ' + t('matchedFiles', 'matched files'));
      $('#yotm_manifest_page').text(formatTemplate(t('pageOf', 'Page %1$s of %2$s'), {'%1$s': manifestPage, '%2$s': manifestPages}));
      $('#yotm_manifest_prev').prop('disabled', manifestPage <= 1);
      $('#yotm_manifest_next').prop('disabled', manifestPage >= manifestPages);
    }).fail(function(){
      $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoadFailed', 'Could not load the manifest.')) + '</td></tr>');
    });
  }

  function renderPruneReview(data) {
    pruneReviewData = data;
    pruneTotal = parseInt(data.total || 0, 10);
    pruneManifest = data.manifest_hash || '';
    $('#yotm_review_count').text(pruneTotal.toLocaleString());
    $('#yotm_review_size').text(data.estimated_bytes_human || formatBytes(data.estimated_bytes));
    $('#yotm_review_scope').text(data.scan_base || (data.context && data.context.scan_base_label) || 'uploads/');
    $('#yotm_review_expiry').text(formatDate(data.expires_at));
    $('#yotm_review_hash').text(pruneManifest);
    $('#yotm_review_orphans').html(renderOrphanSummary(data.orphan_summary || (data.context && data.context.orphan_summary)));
    $('#yotm_review_confirm').prop('checked', false).prop('disabled', false);
    $pruneApprove.text(String(t('deleteReviewedCount', 'Delete %s reviewed files')).replace('%s', pruneTotal.toLocaleString())).prop('disabled', true).removeClass('yo-hidden');
    $('#yotm_manifest_search').val('').prop('disabled', false);
    $('#yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
    $pruneReview.removeClass('yo-hidden');
    setPruneStep('review');
    loadManifest(1);
    window.setTimeout(function(){ $pruneReview.trigger('focus'); }, 50);
  }

  $('#yotm_manifest_prev').on('click', function(){ if (manifestPage > 1) { loadManifest(manifestPage - 1); } });
  $('#yotm_manifest_next').on('click', function(){ if (manifestPage < manifestPages) { loadManifest(manifestPage + 1); } });
  $('#yotm_manifest_search').on('input', function(){
    window.clearTimeout(manifestTimer);
    manifestTimer = window.setTimeout(function(){ loadManifest(1); }, 250);
  });
  $('#yotm_review_confirm').on('change', function(){ $pruneApprove.prop('disabled', !this.checked); });

  function preparePrune() {
    if (pruneRunning || pruneToken) {
      return;
    }
	const limitSubpaths = gatherPruneSubpaths();
	if ($('input[name="yotm_prune_scope"]:checked').val() === 'selected' && !limitSubpaths.length) {
		$pruneResults.html(htmlNotice('notice-error', t('noFolderSelected', 'Choose at least one folder or use All uploads.'))).trigger('focus');
		return;
	}
    pruneRunning = true;
    setPruneStep('scanning');
    lockPruneControls(true);
    $pruneRun.prop('disabled', true);
    $pruneCancel.removeClass('yo-hidden');
    $pruneReview.addClass('yo-hidden');
    $pruneResults.empty();
    pruneProgress(1, t('scanning', 'Scanning…'), true);
    $.post(ajaxurl, {
      action: 'yotm_prune_prepare',
      nonce: nonce,
      keep: gatherKeep(),
	  limit_subpaths: limitSubpaths,
      discover_orphans: $('#yotm_discover_orphans').is(':checked') ? 1 : 0
    }).done(function(response){
      if (!response || !response.success || !response.data) {
        const resumeToken = response && response.data ? response.data.resume_token : '';
        if (resumeToken) {
          resumePrune(resumeToken);
          return;
        }
        $pruneResults.html(htmlNotice('notice-error', t('prepareFailed', 'Prepare failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      pruneToken = response.data.token;
      rememberJob('prune', pruneToken);
      scanPruneBatch();
      loadRecentJobs();
    }).fail(function(){
      $pruneResults.html(htmlNotice('notice-error', t('networkPrepare', 'Network error during prepare.')));
      clearPruneJob({keepResults: true, preserveJob: !!pruneToken});
    });
  }

  function scanPruneBatch() {
    const token = pruneToken;
    if (!token || !pruneRunning) {
      return;
    }
    $.post(ajaxurl, {action: 'yotm_prune_scan_batch', nonce: nonce, token: token, batch: 100}).done(function(response){
      if (token !== pruneToken || !pruneRunning) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $pruneResults.prepend(htmlNotice('notice-error', t('scanFailed', 'Scan failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true, preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled' || data.status === 'expired') {
        $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped.')));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        loadRecentJobs();
        return;
      }
      let label = t('scanning', 'Scanning…') + ' ' + data.scan_processed + ' / ' + data.scan_total_attachments;
      let indeterminate = false;
      if (data.scan_phase === 'disk') {
        label = String(t('scanningDiskCount', 'Scanning uploads folders… %s entries checked')).replace('%s', data.disk_entries_processed || 0);
        indeterminate = true;
      } else if (data.scan_phase === 'source_index') {
        label = t('indexingSources', 'Indexing authoritative media sources…');
        indeterminate = true;
      } else if (data.scan_phase === 'selection' && data.total_known === false) {
        label = String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', data.selection_scanned || 0);
        indeterminate = true;
      } else if (data.scan_phase === 'manifest') {
        label = t('buildingManifest', 'Building immutable manifest…');
        indeterminate = true;
      }
      pruneProgress(data.scan_percent || 0, label, indeterminate);
      if (!data.scan_done) {
        window.setTimeout(scanPruneBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      pruneRunning = false;
      if (!parseInt(data.total || 0, 10)) {
        $pruneResults.html(htmlNotice('notice-warning', t('noMatchingThumbnails', 'No matching thumbnails found.')));
        clearPruneJob({keepResults: true});
        setPruneStep('completed');
        loadRecentJobs();
        $pruneResults.trigger('focus');
        return;
      }
      $pruneResults.html(htmlNotice('notice-info', t('scanReadyForReview', 'Scan complete. Review the immutable manifest below; nothing has been deleted.')));
      $pruneProg.hide();
      $pruneStat.hide();
      $pruneCancel.removeClass('yo-hidden');
      renderPruneReview(data);
      loadRecentJobs();
    }).fail(function(){
      if (token !== pruneToken) {
        return;
      }
      $pruneResults.prepend(htmlNotice('notice-error', t('networkScan', 'Network error during scan.') + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearPruneJob({keepResults: true, preserveJob: true});
    });
  }

  function approvePruneManifest() {
    if (!pruneToken || !pruneManifest || !$('#yotm_review_confirm').is(':checked')) {
      return;
    }
    $pruneApprove.prop('disabled', true);
    $('#yotm_review_confirm, #yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', true);
    $.post(ajaxurl, {
      action: 'yotm_prune_approve', nonce: nonce, token: pruneToken,
      manifest_hash: pruneManifest, confirmed: 1
    }).done(function(response){
      if (!response || !response.success) {
        $pruneResults.prepend(htmlNotice('notice-error', t('approvalFailed', 'Delete approval failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        $('#yotm_review_confirm, #yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
        $pruneApprove.prop('disabled', false);
        return;
      }
      pruneRunning = true;
      setPruneStep('deleting');
      pruneProgress(0, t('deleting', 'Deleting…'));
      deletePruneBatch();
      loadRecentJobs();
    }).fail(function(){
      $pruneResults.prepend(htmlNotice('notice-error', t('networkApproval', 'Network error while approving the manifest.')));
      $('#yotm_review_confirm, #yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
      $pruneApprove.prop('disabled', false);
    });
  }

  function deletePruneBatch() {
    const token = pruneToken;
    if (!token || !pruneRunning) {
      return;
    }
    $.post(ajaxurl, {
      action: 'yotm_prune_delete_batch', nonce: nonce, token: token,
      manifest_hash: pruneManifest, batch: 100
    }).done(function(response){
      if (token !== pruneToken || !pruneRunning) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $pruneResults.prepend(htmlNotice('notice-error', t('deleteFailed', 'Delete failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true, keepReview: true, preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled') {
        $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back.')));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        loadRecentJobs();
        return;
      }
      pruneProgress(data.percent || 0, t('deleting', 'Deleting…') + ' ' + data.processed + ' / ' + pruneTotal + ' — ' + data.bytes_human);
      if (!data.done) {
        window.setTimeout(deletePruneBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      let message = formatTemplate(t('doneDeleted', 'Done. Deleted %1$s files — Freed %2$s.'), {'%1$s': data.deleted, '%2$s': data.bytes_human});
      if (data.failed) {
        message += ' ' + t('failedFiles', 'Failed files:') + ' ' + data.failed;
      }
      $pruneResults.html(htmlNotice(data.failed ? 'notice-warning' : 'notice-success', message) + errorDetails(data.errors));
      clearPruneJob({keepResults: true, keepProgress: true});
      setPruneStep('completed');
      $pruneResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== pruneToken) {
        return;
      }
      $pruneResults.prepend(htmlNotice('notice-error', t('networkDelete', 'Network error during delete.') + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearPruneJob({keepResults: true, keepReview: true, preserveJob: true});
    });
  }

  function resumePrune(token) {
    if (!token || (pruneToken === token && (pruneRunning || !$pruneReview.hasClass('yo-hidden')))) {
      return;
    }
    pruneToken = token;
    pruneRunning = true;
    rememberJob('prune', token);
    activateTab('prune');
    lockPruneControls(true);
    $pruneRun.prop('disabled', true);
    $pruneCancel.removeClass('yo-hidden');
    $pruneResults.html(htmlNotice('notice-info', t('resumeAvailable', 'An unfinished job was found. Resuming it now…')));
    getJobStatus(token).done(function(response){
      if (!response || !response.success || !response.data) {
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      const data = response.data;
      pruneManifest = data.manifest_hash || '';
      pruneTotal = parseInt(data.total || 0, 10);
      if (data.status === 'approved' || data.status === 'deleting') {
        setPruneStep('deleting');
        pruneProgress(jobProgress(data), t('deleting', 'Deleting…'));
        deletePruneBatch();
      } else if (data.status === 'scanning') {
        setPruneStep('scanning');
        pruneProgress(jobProgress(data), t('scanning', 'Scanning…'), true);
        scanPruneBatch();
      } else if (data.status === 'awaiting_approval') {
        setPruneStep('scanning');
        scanPruneBatch();
      } else {
        clearPruneJob({keepResults: true});
        setPruneStep(data.status === 'completed' ? 'completed' : 'configure');
      }
    }).fail(function(request){
      if (isMissingJobStatus(request)) {
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      clearPruneJob({keepResults: true, preserveJob: true});
    });
  }

  $pruneRun.on('click', preparePrune);
  $pruneApprove.on('click', approvePruneManifest);
  $pruneCancel.on('click', function(){
    if (!pruneToken) {
      clearPruneJob({keepResults: true});
      return;
    }
    const token = pruneToken;
    pruneRunning = false;
    $pruneCancel.prop('disabled', true);
    $pruneStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token).done(function(){
      $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.')));
      clearPruneJob({keepResults: true});
      setPruneStep('configure');
      $pruneResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      pruneRunning = true;
      $pruneCancel.prop('disabled', false);
      $pruneResults.prepend(htmlNotice('notice-error', t('unknownError', 'Unknown error')));
    });
  });

  // Persistent regeneration jobs.
  let regenToken = '';
  let regenRunning = false;
  const $regenProg = $('#yotm_regen_progress');
  const $regenBar = $('#yotm_regen_progress .bar');
  const $regenStat = $('#yotm_regen_status');
  const $regenResults = $('#yotm_regen_results');
  const $regenRun = $('#yotm_regen_run');
  const $regenCancel = $('#yotm_regen_cancel');

  function toggleRegenScopeFields() {
    const scope = $('#yotm_regen_scope').val();
    $('#yotm_regen_subpath_wrap').toggleClass('yo-hidden', scope !== 'subpath');
    $('#yotm_regen_ids_wrap').toggleClass('yo-hidden', scope !== 'ids');
  }

  function toggleRegenModeNote() {
    $('#yotm_regen_force_note').toggleClass('yo-hidden', $('input[name="yotm_regen_mode"]:checked').val() !== 'force');
  }

  function lockRegenControls(locked) {
    $('#yotm_regen_scope, #yotm_regen_subpath, #yotm_regen_attachment_ids, input[name="yotm_regen_mode"]').prop('disabled', !!locked);
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
    $.post(ajaxurl, {action: 'yotm_regenerate_batch', nonce: nonce, token: token, batch: 20}).done(function(response){
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
    $.post(ajaxurl, {
      action: 'yotm_regenerate_prepare', nonce: nonce,
      scope: $('#yotm_regen_scope').val() || 'all',
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
      if (response && response.success && response.data && response.data.status === 'running') {
        const context = response.data.context || {};
        if (response.data.total_known === false || (context.selector === 'attached_meta_v2' && !context.selection_done)) {
          regenProgress(0, String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', context.selection_scanned || 0), true);
        }
        regenerateBatch();
      } else {
        clearRegenJob({keepResults: true});
      }
    }).fail(function(request){
      clearRegenJob({keepResults: true, preserveJob: !isMissingJobStatus(request)});
    });
  }

  $('#yotm_regen_scope').on('change', toggleRegenScopeFields);
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
      $regenResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.')));
      clearRegenJob({keepResults: true});
      $regenResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){ regenRunning = true; $regenCancel.prop('disabled', false); });
  });

  // Batched recommendation jobs.
  let recommendToken = '';
  let recommendRunning = false;
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

  function renderRecommendationTable(items) {
    if (!Array.isArray(items) || !items.length) {
      return htmlNotice('notice-warning', t('noRecommendationData', 'No recommendation data found.'));
    }
    let html = '<div class="yo-table-scroll"><table class="widefat striped"><thead><tr><th>' + escapeHtml(t('size', 'Size')) + '</th><th>' + escapeHtml(t('dimensions', 'Dimensions')) + '</th><th>' + escapeHtml(t('status', 'Status')) + '</th><th>' + escapeHtml(t('confidence', 'Keep confidence')) + '</th><th>' + escapeHtml(t('evidence', 'Evidence')) + '</th><th>' + escapeHtml(t('reason', 'Reason')) + '</th><th>' + escapeHtml(t('recommendation', 'Recommendation')) + '</th></tr></thead><tbody>';
    items.forEach(function(item){
      const badgeClass = item.status === 'detected_reference' || item.status === 'used' || item.status === 'protected' ? 'yo-safe' : (item.status === 'danger' ? 'yo-danger' : 'yo-warning');
      html += '<tr><td><strong>' + escapeHtml(item.name || '') + '</strong></td><td>' + escapeHtml(item.dimensions || '—') + '</td><td><span class="yo-badge ' + badgeClass + '">' + escapeHtml(item.label || item.status || t('unknown', 'Unknown')) + '</span></td><td>' + escapeHtml(recommendationConfidenceLabel(item.confidence)) + '</td><td>' + renderRecommendationEvidence(item.evidence) + '</td><td>' + escapeHtml(item.reason || '') + '</td><td>' + escapeHtml(item.recommendation || '') + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  function renderRecommendationResult(result) {
    const data = result || {};
    $('#yotm_recommend_protected_count').text(data.protected_count || 0);
    $('#yotm_recommend_reference_count').text(data.detected_reference_count || 0);
    $('#yotm_recommend_unknown_count').text(data.unknown_count || 0);
    $('#yotm_recommend_generated_bytes').text(data.generated_bytes_human || '—');

    const items = Array.isArray(data.items) ? data.items : [];
    const schemaValid = parseInt(data.schema_version, 10) === 2;
    const signatureValid = !!data.registered_sizes_signature && data.registered_sizes_signature === (YOTM.registeredSizesSignature || '');
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

    $recommendResults.html(notice + renderRecommendationTable(items));
    $('#yotm_apply_recommendations').toggleClass('yo-hidden', !recommendationCanApply).prop('disabled', !recommendationCanApply);
    $('#yotm_recommend_go_prune').removeClass('yo-hidden');
    $recommendStat.text(t('recommendationScanCompleted', 'Scan completed.'));
  }

  function recommendationBatch() {
    const token = recommendToken;
    if (!token || !recommendRunning) {
      return;
    }
    $.post(ajaxurl, {action: 'yotm_recommend_batch', nonce: nonce, token: token, batch: 100}).done(function(response){
      if (token !== recommendToken || !recommendRunning) {
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
        window.setTimeout(recommendationBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      renderRecommendationResult(data.result || {});
      clearRecommendation({keepProgress: true});
      $recommendResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== recommendToken) {
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
    recommendRunning = true;
    recommendationCanApply = false;
    recommendationApplyItems = [];
    $recommendRun.prop('disabled', true);
    $recommendCancel.removeClass('yo-hidden');
    $recommendResults.empty();
    $('#yotm_apply_recommendations, #yotm_recommend_go_prune').addClass('yo-hidden');
    recommendationProgress(1, t('scanningMediaUsage', 'Scanning media usage…'));
    $.post(ajaxurl, {action: 'yotm_recommend_prepare', nonce: nonce}).done(function(response){
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
      $recommendResults.html(htmlNotice('notice-error', t('networkRecommendationScan', 'Network error during recommendation scan.')));
      clearRecommendation({preserveJob: !!recommendToken});
    });
  }

  function resumeRecommendation(token) {
    if (!token || (recommendToken === token && recommendRunning)) {
      return;
    }
    recommendToken = token;
    recommendRunning = true;
    rememberJob('recommendation', token);
    $recommendRun.prop('disabled', true);
    $recommendCancel.removeClass('yo-hidden');
    getJobStatus(token).done(function(response){
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
    }).fail(function(request){
      clearRecommendation({preserveJob: !isMissingJobStatus(request)});
    });
  }

  $recommendRun.on('click', prepareRecommendation);
  $recommendCancel.on('click', function(){
    if (!recommendToken) {
      clearRecommendation();
      return;
    }
    const token = recommendToken;
    recommendRunning = false;
    $recommendCancel.prop('disabled', true);
    $recommendStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token).done(function(){
      $recommendResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.')));
      clearRecommendation();
      $recommendResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){ recommendRunning = true; $recommendCancel.prop('disabled', false); });
  });

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

  function resumeDiscoveredJob(job) {
    if (!job) {
      return false;
    }
    return resumeWorkflow(job.type, job.token);
  }

  registerWorkflow('prune', {
    isActive: function(){ return !!pruneToken; },
    resume: resumePrune
  });
  registerWorkflow('regenerate', {
    isActive: function(){ return !!regenToken; },
    prepare: prepareRegenerate,
    resume: resumeRegenerate
  });
  registerWorkflow('recommendation', {
    isActive: function(){ return !!recommendToken; },
    resume: resumeRecommendation
  });

  let started = false;

  function start() {
    if (started) {
      return;
    }
    started = true;

    toggleRegenScopeFields();
    toggleRegenModeNote();
    toggleSubpathPicker();
    filterSubpathOptions();

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
    jobProgress: jobProgress,
    loadRecentJobs: loadRecentJobs,
    recallJob: recallJob,
    registerWorkflow: registerWorkflow,
    rememberJob: rememberJob,
    request: request,
    responseError: responseError,
    t: t
  });

  start();
})(jQuery);
