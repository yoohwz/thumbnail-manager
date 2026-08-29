(function($, core){
  'use strict';

  if (!core) {
    return;
  }

  const t = core.t;
  const activateTab = core.activateTab;
  const coreSet = new Set(['thumbnail', 'medium', 'large', 'medium_large', '1536x1536', '2048x2048']);

  $('#yotm_sizes_enable_core').on('click', function(){
    $('input[name="yotm_enable_sizes[]"]').each(function(){ this.checked = coreSet.has(this.value); });
  });
  $('#yotm_sizes_enable_all').on('click', function(){ $('input[name="yotm_enable_sizes[]"]').prop('checked', true); });
  $('#yotm_sizes_disable_all').on('click', function(){ $('input[name="yotm_enable_sizes[]"]').prop('checked', false); });

  $('#yotm_sizes_prune_disabled').on('click', function(){
    const enabled = new Set();
    $('input[name="yotm_enable_sizes[]"]:checked').each(function(){ enabled.add(this.value); });
    const allSizes = $('.yotm_keep').map(function(){ return this.value; }).get();
    if (enabled.size === allSizes.length) {
      window.alert(t('allSizesEnabled', 'All sizes are enabled — there are no disabled sizes to prune.'));
      return;
    }
    activateTab('prune');
    $('.yotm_keep').each(function(){ this.checked = enabled.has(this.value); });
    // Human-approved bridge: enable discovery and stop at immutable review.
    $('#yotm_discover_orphans').prop('checked', true);
	$('#yotm_discover_historical').prop('checked', false);
    $('#yotm_run').trigger('click');
  });

  $(document).on('click', 'button[name="yotm_save_and_regenerate"]', function(){
    const $form = $(this).closest('form');
    if (!$form.length) {
      return;
    }
    let $hidden = $form.find('input[type="hidden"][name="yotm_save_and_regenerate"]');
    if (!$hidden.length) {
      $hidden = $('<input>', {type: 'hidden', name: 'yotm_save_and_regenerate'}).appendTo($form);
    }
    $hidden.val('1');
  });
})(jQuery, window.YOTMAdmin);
