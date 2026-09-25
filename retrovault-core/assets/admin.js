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
			if ($field.hasClass('rv-media--file')) {
				romChanged(att.filename || att.title || '');
			}
		});
		frame.open();
	});

	/* ---------- جاهزية اللعبة ---------- */
	var i18n = cfg.i18n || {};

	function extOf(name) {
		var m = /\.([a-z0-9]+)$/i.exec(String(name || '').split(/[?#]/)[0]);
		return m ? m[1].toLowerCase() : '';
	}

	function romName() {
		var $file = $('.rv-media--file');
		var name = $file.find('input[type=hidden]').val() ? $.trim($file.find('[data-rv-preview] code').text()) : '';
		var url = $.trim($('#rv-rom-url').val() || '');
		return name || (url ? url.split(/[?#]/)[0].split('/').pop() : '');
	}

	function checkedSystem() {
		var $r = $('.rv-system-radios input:checked');
		return $r.length ? { key: String($r.data('system-key') || ''), name: $.trim($r.parent().text()) } : null;
	}

	function mismatch(ext, sys) {
		var def = sys && cfg.systems[sys.key];
		return !!(ext && def && def.ext.length && def.ext.concat(['zip', '7z']).indexOf(ext) === -1);
	}

	/* مثل Systems::for_extension: نظام واحد يقبل الامتداد، أو واحد هو امتداده الأصلي */
	function systemFor(ext) {
		var any = [];
		var primary = [];
		var seen = {};
		$('.rv-system-radios input[data-system-key]').each(function () {
			var key = String($(this).data('system-key') || '');
			var def = cfg.systems[key];
			if (!key || !def || seen[key]) { return; }
			seen[key] = true;
			var exts = def.ext.map(function (e) { return String(e).toLowerCase(); });
			if (exts.indexOf(ext) !== -1) {
				any.push(this);
				if (exts[0] === ext) { primary.push(this); }
			}
		});
		return any.length === 1 ? any[0] : (primary.length === 1 ? primary[0] : null);
	}

	function setItem(key, state, note) {
		var $li = $('[data-rv-ready-item="' + key + '"]');
		$li.removeClass('is-ok is-missing is-optional').addClass(state);
		$li.find('.rv-ready__mark .screen-reader-text').text((i18n.states || {})[state] || '');
		$li.find('.rv-ready__note').text(note || '');
	}

	function updateReady() {
		if (!$('[data-rv-ready]').length) { return; }
		var name = romName();
		var sys = checkedSystem();
		var bad = mismatch(extOf(name), sys);
		setItem('file', name ? 'is-ok' : 'is-missing', name || i18n.noFile);
		setItem('system', sys ? 'is-ok' : 'is-missing', sys ? sys.name : i18n.noSystem);
		setItem('match', bad ? 'is-missing' : 'is-ok', bad ? i18n.mismatch : '');
		$('[data-rv-ready-item="match"]').prop('hidden', !(name && sys));
		var cover = parseInt($('#_thumbnail_id').val(), 10) > 0;
		setItem('cover', cover ? 'is-ok' : 'is-optional', cover ? '' : i18n.coverHint);
		var lede = $.trim($('#excerpt').val() || '') !== '';
		setItem('excerpt', lede ? 'is-ok' : 'is-optional', lede ? '' : i18n.excerptHint);
	}

	/* ملف جديد: النظام من امتداده إن لم يُختر، والاسم من اسم الملف إن كان فارغاً */
	function romChanged(filename) {
		var ext = extOf(filename);
		var $note = $('#rv-auto-system');
		$note.text('').prop('hidden', true);
		if (ext && !$('.rv-system-radios input:checked').length) {
			var input = systemFor(ext);
			if (input) {
				$(input).prop('checked', true).trigger('change');
				$note.text(String(i18n.autoSystem || '').replace('%s', $.trim($(input).parent().text()))).prop('hidden', false);
			} else if (['zip', '7z'].indexOf(ext) === -1) {
				$note.text(String(i18n.sharedExt || '').replace('%s', ext)).prop('hidden', false);
			}
		}
		var $title = $('#title');
		if (filename && $title.length && !$.trim($title.val())) {
			var title = String(filename).split(/[?#]/)[0].replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
			$title.val(title.replace(/(^|\s)([a-z])/g, function (m, sp, c) { return sp + c.toUpperCase(); })).trigger('input');
			$('#title-prompt-text').addClass('screen-reader-text');
		}
		updateReady();
	}

	$(document).on('click', '[data-rv-clear]', function (e) {
		e.preventDefault();
		var $field = $(this).closest('[data-rv-media]');
		$field.find('input[type=hidden]').val('').trigger('change');
		$(this).prop('hidden', true);
		renderPreview($field, null);
		updateReady();
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

		if ($('[data-rv-ready]').length) {
			$(document).on('change', '.rv-system-radios input', updateReady);
			$('#rv-rom-url').on('change', function () { romChanged(this.value); });
			$('#excerpt').on('input', updateReady);
			/* صندوق «غلاف اللعبة» يُعاد رسمه عند التعيين والإزالة */
			var cover = document.getElementById('postimagediv');
			if (cover && window.MutationObserver) {
				new MutationObserver(updateReady).observe(cover, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
			}
			updateReady();

			/* نشر لعبة لن تعمل للزوار: تأكيد أولاً (الحفظ كمسودة بلا تأكيد) */
			$('#publish').on('click', function (e) {
				if ($('#original_post_status').val() === 'publish') { return; }
				var name = romName();
				var sys = checkedSystem();
				var why = [];
				if (!name) { why.push(i18n.whyNoFile); }
				if (!sys) { why.push(i18n.whyNoSystem); } else if (mismatch(extOf(name), sys)) { why.push(i18n.whyMismatch); }
				if (why.length && !window.confirm(String(i18n.confirmPublish || '').replace('%s', why.join('، ')))) {
					e.preventDefault();
					e.stopImmediatePropagation();
				}
			});
		}
	});
})(jQuery);
