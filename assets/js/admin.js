/**
 * Admin JavaScript
 *
 * @package Oli_Cron_Jobs
 */

(function ($) {
	'use strict';

	/**
	 * Show the URL field when a URL event is selected.
	 */
	function oliCjToggleEventTypeFields() {
		var type = $('#event_type').val();
		var $hook = $('#hook');

		$('#url_field, #php_field').addClass('oli-cj-hidden');

		if ('url' === type) {
			$('#url_field').removeClass('oli-cj-hidden');
			$hook.prop('required', true);
		} else if ('php' === type) {
			$('#php_field').removeClass('oli-cj-hidden');
			$hook.prop('required', false);
		} else {
			$hook.prop('required', true);
		}
	}

	$(function () {
		if ($('#event_type').length) {
			oliCjToggleEventTypeFields();
			$('#event_type').on('change', oliCjToggleEventTypeFields);
		}

		$(document).on('click', '.oli-cj-confirm', function (event) {
			var message = $(this).attr('data-oli-cj-confirm');

			if (!message && typeof oli_cj_admin !== 'undefined') {
				message = oli_cj_admin.confirmGeneric;
			}

			if (message && !window.confirm(message)) {
				event.preventDefault();
				return false;
			}

			return true;
		});

		$('#oli-cj-events-form').on('submit', function (event) {
			var action = $(this).find('select[name="action"]').val();
			var message = typeof oli_cj_admin !== 'undefined' ? oli_cj_admin.confirmBulkDelete : '';

			if ('delete' === action && message && !window.confirm(message)) {
				event.preventDefault();
				return false;
			}

			return true;
		});
	});
})(jQuery);
