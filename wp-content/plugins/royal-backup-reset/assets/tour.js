(function ($) {

	$(function() {
		/*
			Plugins page
			Welcome modal on activation
		*/

		$('.royalbr-welcome .close').on('click', function(e) {
			e.preventDefault();
			$(this).closest('.royalbr-welcome').remove();
		});

		/*
			Royal Backup page tour
		*/

		// if Shepherd is undefined, exit.
		if (!window.Shepherd) return;

		var button_classes = 'button button-primary';
		var plugins_page_tour = window.royalbr_plugins_page_tour = new Shepherd.Tour();
		var main_tour = window.royalbr_main_tour = new Shepherd.Tour();

		// Tour state flags
		main_tour.canceled = false;
		main_tour.going_somewhere = false;

		// Custom complete function to set canceled flag
		main_tour.completeAndCancel = function() {
			main_tour.canceled = true;
			main_tour.complete();
		};

		// Set up the defaults for each step
		main_tour.options.defaults = plugins_page_tour.options.defaults = {
			classes: 'shepherd-theme-arrows-plain-buttons shepherd-main-tour',
			showCancelLink: true,
			scrollTo: false,
			tetherOptions: {
				constraints: [
					{
						to: 'window',
						attachment: 'together',
						pin: true
					}
				]
			}
		};

		/*
			Plugins page welcome modal
		*/

		plugins_page_tour.addStep('intro', {
			title: royalbr_tour_i18n.plugins_page.title,
			text: royalbr_tour_i18n.plugins_page.text,
			attachTo: '.js-royalbr-settings top',
			buttons: [
				{
					classes: button_classes,
					text: royalbr_tour_i18n.plugins_page.button.text,
					action: function() {
						window.location = royalbr_tour_i18n.plugins_page.button.url;
					}
				}
			],
			tetherOptions: {
				constraints: [
					{
						to: 'window',
						attachment: 'together',
						pin: true
					}
				],
				offset: '20px 0'
			},
			when: {
				show: function() {
					$('body').addClass('highlight-royalbr');
					var popup = $(this.el);
					$('body, html').animate({
						scrollTop: popup.offset().top - 50
					}, 500, function() {
						window.scrollTo(0, popup.offset().top - 50);
					});
				},
				hide: function() {
					$('body').removeClass('highlight-royalbr');
				}
			}
		});

		/*
			Main plugin page tour
		*/

		// 1. Your first backup
		main_tour.addStep('backup_now', {
			title: royalbr_tour_i18n.backup_now.title,
			text: royalbr_tour_i18n.backup_now.text,
			attachTo: '#royalbr-create-backup bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					var self = this;
					var $tooltip = $(this.el);

					// Hide tooltip during transition
					$tooltip.css('opacity', '0');

					// Set flag to prevent circular navigation
					main_tour.going_somewhere = true;

					// Click the backup tab when showing this step
					$('.royalbr-nav-tab[data-tab="backup-website"]').trigger('click');

					// Wait for tab content to render, then reposition and show
					setTimeout(function() {
						if (self.tether) {
							self.tether.position();
						}
						// Show tooltip after repositioning
						$tooltip.css('opacity', '1');

						var popup = $(self.el);
						$('body, html').animate({
							scrollTop: popup.offset().top - 50
						}, 500);

						// Reset flag after transition completes
						main_tour.going_somewhere = false;
					}, 100);
				}
			}
		});

		// 2. Restore tab
		main_tour.addStep('restore_tab', {
			title: royalbr_tour_i18n.restore_tab.title,
			text: royalbr_tour_i18n.restore_tab.text,
			attachTo: '.royalbr-nav-tab[data-tab="restore-website"] bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					// Set flag to prevent circular navigation
					main_tour.going_somewhere = true;

					// Click the restore tab when showing this step
					$('.royalbr-nav-tab[data-tab="restore-website"]').trigger('click');

					// Reset flag after tab transition
					setTimeout(function() {
						main_tour.going_somewhere = false;
					}, 100);
				}
			}
		});

		// 3. Reset database tab
		main_tour.addStep('reset_tab', {
			title: royalbr_tour_i18n.reset_tab.title,
			text: royalbr_tour_i18n.reset_tab.text,
			attachTo: '.royalbr-nav-tab[data-tab="reset-database"] bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			],
			when: {
				show: function() {
					// Set flag to prevent circular navigation
					main_tour.going_somewhere = true;

					// Click the reset tab when showing this step
					$('.royalbr-nav-tab[data-tab="reset-database"]').trigger('click');

					// Reset flag after tab transition
					setTimeout(function() {
						main_tour.going_somewhere = false;
					}, 100);
				}
			}
		});

		// 4. Reset button
		main_tour.addStep('reset_button', {
			title: royalbr_tour_i18n.reset_button.title,
			text: royalbr_tour_i18n.reset_button.text,
			attachTo: '#royalbr-reset-database bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			]
		});

		// 5. Admin bar backup icon
		main_tour.addStep('admin_bar_backup', {
			title: royalbr_tour_i18n.admin_bar_backup.title,
			text: royalbr_tour_i18n.admin_bar_backup.text,
			attachTo: '#wp-admin-bar-royalbr_backup_node bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			],
			tetherOptions: {
				constraints: [
					{
						to: 'window',
						attachment: 'together',
						pin: true
					}
				],
				offset: '0 -10px'
			}
		});

		// 6. Admin bar reset icon
		main_tour.addStep('admin_bar_reset', {
			title: royalbr_tour_i18n.admin_bar_reset.title,
			text: royalbr_tour_i18n.admin_bar_reset.text,
			attachTo: '#wp-admin-bar-royalbr_reset_node bottom',
			buttons: [
				{
					classes: 'royalbr-tour-end',
					text: royalbr_tour_i18n.end_tour,
					action: main_tour.completeAndCancel
				},
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.next,
					action: main_tour.next
				}
			],
			tetherOptions: {
				constraints: [
					{
						to: 'window',
						attachment: 'together',
						pin: true
					}
				],
				offset: '0 -10px'
			}
		});

		// 7. Settings tab (final step)
		main_tour.addStep('settings_tab', {
			title: royalbr_tour_i18n.settings_tab.title,
			text: royalbr_tour_i18n.settings_tab.text,
			attachTo: '.royalbr-nav-tab[data-tab="settings"] bottom',
			buttons: [
				{
					classes: 'royalbr-tour-back',
					text: royalbr_tour_i18n.back,
					action: main_tour.back
				},
				{
					classes: button_classes,
					text: royalbr_tour_i18n.finish,
					action: main_tour.completeAndCancel
				}
			],
			when: {
				show: function() {
					// Set flag to prevent circular navigation
					main_tour.going_somewhere = true;

					// Click the settings tab when showing this step
					$('.royalbr-nav-tab[data-tab="settings"]').trigger('click');

					// Reset flag after tab transition
					setTimeout(function() {
						main_tour.going_somewhere = false;
					}, 100);
				}
			}
		});

		/*
			Tab click listeners for bidirectional tour/tab synchronization
		*/

		// Backup tab click
		$('.royalbr-nav-tab[data-tab="backup-website"]').on('click', function(e) {
			if (!main_tour.canceled && !main_tour.going_somewhere) {
				main_tour.show('backup_now');
			}
		});

		// Restore tab click
		$('.royalbr-nav-tab[data-tab="restore-website"]').on('click', function(e) {
			if (!main_tour.canceled && !main_tour.going_somewhere) {
				main_tour.show('restore_tab');
			}
		});

		// Reset tab click
		$('.royalbr-nav-tab[data-tab="reset-database"]').on('click', function(e) {
			if (!main_tour.canceled && !main_tour.going_somewhere) {
				main_tour.show('reset_button');
			}
		});

		// Settings tab click
		$('.royalbr-nav-tab[data-tab="settings"]').on('click', function(e) {
			if (!main_tour.canceled && !main_tour.going_somewhere) {
				main_tour.show('settings_tab');
			}
		});

		// Start tour on plugins page if Shepherd library is loaded
		if ($('body').hasClass('plugins-php') && window.Shepherd) {
			plugins_page_tour.start();
		}

		// Start main tour on plugin settings page
		if ($('body').hasClass('toplevel_page_royal-backup-reset') && window.Shepherd && $('#royalbr-create-backup').length) {
			main_tour.start();
		}

	});

})(jQuery);
