/* RetroVault — لوحة التحكم */
(function ($) {
	'use strict';

	var cfg = window.RVAdmin || { systems: {}, i18n: {} };

	/* ---------- ملف / صورة مفردة ---------- */
	function renderPreview($field, att) {
		var $preview = $field.find('[data-rv-preview]');
		if (!att) {
			$preview.empty();
			return;
		}
		if ($field.data('type') === 'image') {
			var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
			$preview.html($('<img alt="">').attr('src', url));
		} else {
			var $code = $('<code>').text(att.filename || att.title);
			$preview.empty().append($code);
			if (att.filesizeHumanReadable) {
				$preview.append(' ', $('<span>').text(att.filesizeHumanReadable));
			}
		}
	}

	$(document).on('click', '[data-rv-pick]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $field = $btn.closest('[data-rv-media]');
		var type = $field.data('type');
		var frame = wp.media({
			title: $btn.data('title'),
			button: { text: $btn.data('button') },
			library: type ? { type: type } : {},
			multiple: false
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			$field.find('input[type=hidden]').val(att.id).trigger('change');
			$field.find('[data-rv-clear]').prop('hidden', false);
			renderPreview($field, att);
		});
		frame.open();
	});

	$(document).on('click', '[data-rv-clear]', function (e) {
		e.preventDefault();
		var $field = $(this).closest('[data-rv-media]');
		$field.find('input[type=hidden]').val('').trigger('change');
		$(this).prop('hidden', true);
		renderPreview($field, null);
	});

	/* ---------- معرض اللقطات ---------- */
	function syncGallery($gallery) {
		var ids = $gallery.find('[data-rv-gallery-list] li').map(function () {
			return $(this).data('id');
		}).get();
		$gallery.find('input[type=hidden]').val(ids.join(','));
	}

	function galleryItem(att) {
		var thumb = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
		return $('<li>').attr('data-id', att.id)
			.append($('<img alt="">').attr('src', thumb))
			.append($('<button type="button" class="rv-gallery__remove">×</button>').attr('aria-label', cfg.i18n.remove || ''));
	}

	$(document).on('click', '[data-rv-gallery-add]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $gallery = $btn.closest('[data-rv-gallery]');
		var frame = wp.media({
			title: $btn.data('title'),
			button: { text: $btn.data('button') },
			library: { type: 'image' },
			multiple: 'add'
		});
		frame.on('select', function () {
			var $list = $gallery.find('[data-rv-gallery-list]');
			frame.state().get('selection').each(function (model) {
				var att = model.toJSON();
				if (!$list.find('li[data-id="' + att.id + '"]').length) {
					$list.append(galleryItem(att));
				}
			});
			syncGallery($gallery);
		});
		frame.open();
	});

	$(document).on('click', '.rv-gallery__remove', function (e) {
		e.preventDefault();
		var $gallery = $(this).closest('[data-rv-gallery]');
		$(this).closest('li').remove();
		syncGallery($gallery);
	});

	$(function () {
		$('[data-rv-gallery-list]').each(function () {
			var $list = $(this);
			if ($.fn.sortable) {
				$list.sortable({
					items: 'li',
					tolerance: 'pointer',
					update: function () { syncGallery($list.closest('[data-rv-gallery]')); }
				});
			}
		});

		if ($.fn.wpColorPicker) {
			$('.rv-color-field').wpColorPicker();
		}

		/* ---------- الأنوية والامتدادات حسب النظام المختار ---------- */
		var $core = $('#rv-core');
		function syncSystem() {
			var key = $('.rv-system-radios input:checked').data('system-key');
			var sys = cfg.systems[key];
			var current = $core.val() || $core.data('current') || '';
			$core.empty().append(new Option(cfg.i18n.defaultCore, ''));
			if (sys) {
				sys.cores.forEach(function (c) {
					$core.append(new Option(c, c, false, c === current));
				});
			}
			$('#rv-ext-hint').text(sys ? '.' + sys.ext.join(' .') : cfg.i18n.pickSystem);
		}
		if ($core.length) {
			$(document).on('change', '.rv-system-radios input', syncSystem);
		}
	});
})(jQuery);
