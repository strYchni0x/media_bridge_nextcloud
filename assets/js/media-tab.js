/* global wp, jQuery, NCMB */
( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.media || ! NCMB ) {
		return;
	}

	/**
	 * Custom view that renders the Nextcloud folder and triggers imports.
	 */
	var NextcloudBrowser = wp.media.View.extend( {
		className: 'ncmb-browser',

		events: {
			'click .ncmb-entry-dir': 'openDir',
			'click .ncmb-up': 'goUp',
			'click .ncmb-import': 'importImage',
			'click .ncmb-page': 'gotoPage',
			'change .ncmb-select': 'updateSelectionUI',
			'click .ncmb-select-all': 'selectAll',
			'click .ncmb-select-folder': 'selectFolder',
			'click .ncmb-select-none': 'selectNone',
			'click .ncmb-import-selected': 'importSelected',
		},

		initialize: function ( options ) {
			this.controller = options.controller;
			this.currentPath = '/';
			this.rootPath = '/';
			this.currentPage = 1;
			this.perPage = 40;
			// Cross-page selection model: path -> true. Persists across page
			// changes; the checkboxes only mirror it.
			this.selection = {};
		},

		render: function () {
			this.load( this.currentPath, 1 );
			return this;
		},

		request: function ( route, params, method ) {
			return $.ajax( {
				url: NCMB.restUrl + route,
				method: method || 'GET',
				data: params,
				beforeSend: function ( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', NCMB.nonce );
				},
			} );
		},

		load: function ( path, page ) {
			var self = this;
			page = page || 1;
			this.$el.html( '<div class="ncmb-status">' + NCMB.i18n.loading + '</div>' );

			this.request( '/list', { path: path, page: page, per_page: this.perPage } )
				.done( function ( data ) {
					self.currentPath = data.path;
					self.currentPage = data.pagination ? data.pagination.page : 1;
					if ( data.root_path ) {
						self.rootPath = data.root_path;
					}
					self.renderListing( data );
				} )
				.fail( function ( xhr ) {
					self.$el.html(
						'<div class="ncmb-status ncmb-error">' +
							( xhr.responseJSON && xhr.responseJSON.message
								? xhr.responseJSON.message
								: NCMB.i18n.error ) +
							'</div>'
					);
				} );
		},

		renderListing: function ( data ) {
			var html = '<div class="ncmb-toolbar">';
			html += '<span class="ncmb-path">' + escapeHtml( data.path ) + '</span>';
			if ( data.path !== this.rootPath && data.path !== '/' ) {
				html += '<button type="button" class="button ncmb-up">↑ ' + NCMB.i18n.up + '</button>';
			}
			html += '</div>';

			var dirs = data.dirs || [];
			var images = data.images || [];
			var pg = data.pagination || { page: 1, total_pages: 1, total: images.length };

			if ( dirs.length ) {
				html += '<ul class="ncmb-dirs">';
				dirs.forEach( function ( d ) {
					html +=
						'<li><a href="#" class="ncmb-entry-dir" data-path="' +
						escapeAttr( d.path ) +
						'">📁 ' +
						escapeHtml( d.name ) +
						'</a></li>';
				} );
				html += '</ul>';
			}

			if ( images.length ) {
				html +=
					'<div class="ncmb-selbar">' +
					'<button type="button" class="button ncmb-select-all">' +
					NCMB.i18n.selectAll +
					'</button>' +
					'<button type="button" class="button ncmb-select-folder">' +
					NCMB.i18n.selectFolder +
					'</button>' +
					'<button type="button" class="button ncmb-select-none">' +
					NCMB.i18n.selectNone +
					'</button>' +
					'<button type="button" class="button button-primary ncmb-import-selected" disabled>' +
					NCMB.i18n.importSelected.replace( '%s', 0 ) +
					'</button>' +
					'<span class="ncmb-sel-status"></span>' +
					'</div>';

				var selection = this.selection;
				html += '<ul class="ncmb-grid">';
				images.forEach( function ( img ) {
					var isSel = !! selection[ img.path ];
					var thumb = img.file_id
						? NCMB.restUrl +
							'/thumb?file_id=' +
							encodeURIComponent( img.file_id ) +
							'&path=' +
							encodeURIComponent( img.path ) +
							'&size=256&bytes=' +
							encodeURIComponent( img.size || 0 ) +
							'&_wpnonce=' +
							encodeURIComponent( NCMB.nonce )
						: '';
					html +=
						'<li class="ncmb-tile' +
						( isSel ? ' ncmb-selected' : '' ) +
						'" data-path="' +
						escapeAttr( img.path ) +
						'">' +
						'<label class="ncmb-check"><input type="checkbox" class="ncmb-select"' +
						( isSel ? ' checked' : '' ) +
						' /></label>' +
						'<div class="ncmb-thumb' +
						( thumb ? '' : ' ncmb-noimg' ) +
						'">' +
						( thumb
							? '<img class="ncmb-thumb-img" loading="lazy" alt="" src="' +
								escapeAttr( thumb ) +
								'">'
							: '' ) +
						'</div>' +
						'<div class="ncmb-name" title="' +
						escapeAttr( img.name ) +
						'">' +
						escapeHtml( img.name ) +
						'</div>' +
						'<button type="button" class="button button-primary ncmb-import">' +
						NCMB.i18n.import +
						'</button>' +
						'</li>';
				} );
				html += '</ul>';
			} else if ( ! dirs.length ) {
				html += '<div class="ncmb-status">' + NCMB.i18n.empty + '</div>';
			}

			html += this.renderPagination( pg );

			this.$el.html( html );

			// Gracefully handle a missing preview (preview disabled, etc.).
			this.$( '.ncmb-thumb-img' ).on( 'error', function () {
				$( this ).closest( '.ncmb-thumb' ).addClass( 'ncmb-noimg' ).empty();
			} );

			// Sync the selection counter with the (possibly cross-page) model.
			this.refreshSelectionCount();
		},

		renderPagination: function ( pg ) {
			if ( ! pg || pg.total_pages <= 1 ) {
				return '';
			}
			var label = NCMB.i18n.pageOf
				.replace( '%1$s', pg.page )
				.replace( '%2$s', pg.total_pages );
			var prevDisabled = pg.page <= 1 ? ' disabled' : '';
			var nextDisabled = pg.page >= pg.total_pages ? ' disabled' : '';
			return (
				'<div class="ncmb-pagination">' +
				'<button type="button" class="button ncmb-page" data-page="' +
				( pg.page - 1 ) +
				'"' +
				prevDisabled +
				'>‹ ' +
				NCMB.i18n.prev +
				'</button>' +
				'<span class="ncmb-page-label">' +
				escapeHtml( label ) +
				'</span>' +
				'<button type="button" class="button ncmb-page" data-page="' +
				( pg.page + 1 ) +
				'"' +
				nextDisabled +
				'>' +
				NCMB.i18n.next +
				' ›</button>' +
				'</div>'
			);
		},

		gotoPage: function ( e ) {
			e.preventDefault();
			var page = parseInt( $( e.currentTarget ).data( 'page' ), 10 );
			if ( ! isNaN( page ) && page > 0 ) {
				this.load( this.currentPath, page );
			}
		},

		/**
		 * Updates the selection button based on the total count in the model
		 * (across all pages).
		 */
		refreshSelectionCount: function () {
			var count = Object.keys( this.selection ).length;
			this.$( '.ncmb-import-selected' )
				.prop( 'disabled', 0 === count )
				.text( NCMB.i18n.importSelected.replace( '%s', count ) );
		},

		updateSelectionUI: function ( e ) {
			if ( e ) {
				var $cb = $( e.currentTarget );
				var $tile = $cb.closest( '.ncmb-tile' );
				var path = $tile.data( 'path' );
				if ( $cb.prop( 'checked' ) ) {
					this.selection[ path ] = true;
					$tile.addClass( 'ncmb-selected' );
				} else {
					delete this.selection[ path ];
					$tile.removeClass( 'ncmb-selected' );
				}
			}
			this.refreshSelectionCount();
		},

		// Adds all images of the current page to the (cross-page) selection.
		selectAll: function ( e ) {
			e.preventDefault();
			var self = this;
			this.$( '.ncmb-tile' ).each( function () {
				var $tile = $( this );
				self.selection[ $tile.data( 'path' ) ] = true;
				$tile.addClass( 'ncmb-selected' ).find( '.ncmb-select' ).prop( 'checked', true );
			} );
			this.refreshSelectionCount();
		},

		// Clears the entire selection (all pages).
		selectNone: function ( e ) {
			e.preventDefault();
			this.selection = {};
			this.$( '.ncmb-select' ).prop( 'checked', false );
			this.$( '.ncmb-tile' ).removeClass( 'ncmb-selected' );
			this.refreshSelectionCount();
		},

		/**
		 * Selects every image of the current folder (across all pages) by
		 * fetching the full path list from the server into the selection model.
		 * Importing then happens via "Import selection".
		 */
		selectFolder: function ( e ) {
			e.preventDefault();
			var self = this;
			var $btn = this.$( '.ncmb-select-folder' );
			var $status = this.$( '.ncmb-sel-status' );
			$btn.prop( 'disabled', true );
			$status.text( NCMB.i18n.loading );

			this.request( '/paths', { path: this.currentPath } )
				.done( function ( data ) {
					( data.paths || [] ).forEach( function ( p ) {
						self.selection[ p ] = true;
					} );
					// Mark the visible tiles of the current page.
					self.$( '.ncmb-tile' ).each( function () {
						var $tile = $( this );
						if ( self.selection[ $tile.data( 'path' ) ] ) {
							$tile.addClass( 'ncmb-selected' ).find( '.ncmb-select' ).prop( 'checked', true );
						}
					} );
					$status.text( '' );
					self.refreshSelectionCount();
				} )
				.fail( function ( xhr ) {
					$status.text(
						xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: NCMB.i18n.error
					);
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		},

		/**
		 * Imports all selected images one after another (one request each, to
		 * avoid timeouts), cross-page from the selection model. For images whose
		 * tile is currently visible the progress is shown on the tile; for the
		 * rest only the counter runs.
		 */
		importSelected: function ( e ) {
			e.preventDefault();
			var self = this;
			var paths = Object.keys( this.selection );
			if ( ! paths.length ) {
				return;
			}

			// Lock the controls while the bulk import runs.
			this.$( '.ncmb-selbar .button, .ncmb-import, .ncmb-page' ).prop( 'disabled', true );

			var ids = [];
			var ok = 0;
			var failed = 0;
			var i = 0;
			var total = paths.length;
			var $status = this.$( '.ncmb-sel-status' );

			function tileFor( path ) {
				var $found = null;
				self.$( '.ncmb-tile' ).each( function () {
					if ( $( this ).data( 'path' ) === path ) {
						$found = $( this );
					}
				} );
				return $found;
			}

			function next() {
				if ( i >= total ) {
					$status.text(
						NCMB.i18n.batchDone.replace( '%1$s', ok ).replace( '%2$s', failed )
					);
					self.$( '.ncmb-import, .ncmb-page, .ncmb-select-all, .ncmb-select-folder, .ncmb-select-none' ).prop( 'disabled', false );
					self.refreshSelectionCount();
					self.selectMultipleInLibrary( ids );
					return;
				}

				var path = paths[ i ];
				var $tile = tileFor( path );
				var $btn = $tile ? $tile.find( '.ncmb-import' ) : null;
				if ( $btn ) {
					$btn.text( NCMB.i18n.importing );
				}
				$status.text(
					NCMB.i18n.batchProgress.replace( '%1$s', i + 1 ).replace( '%2$s', total )
				);

				self.request( '/import', { path: path }, 'POST' )
					.done( function ( res ) {
						ok++;
						delete self.selection[ path ];
						if ( $tile ) {
							$tile.removeClass( 'ncmb-selected' ).addClass( 'ncmb-done' );
							$tile.find( '.ncmb-select' ).prop( 'checked', false );
						}
						if ( $btn ) {
							$btn.text( '✔ ' + NCMB.i18n.imported );
						}
						if ( res && res.id ) {
							ids.push( res.id );
						}
						self.trigger( 'ncmb:imported' );
					} )
					.fail( function () {
						failed++;
						if ( $tile ) {
							$tile.addClass( 'ncmb-failed' );
						}
						if ( $btn ) {
							$btn.text( NCMB.i18n.error );
						}
					} )
					.always( function () {
						i++;
						next();
					} );
			}

			next();
		},

		/**
		 * Adds several imported attachments to the dialog selection and switches
		 * to the media library view.
		 */
		selectMultipleInLibrary: function ( ids ) {
			var frame = this.controller;
			if ( ! frame || ! ids || ! ids.length ) {
				return;
			}
			try {
				var state = frame.state();
				var selection = state && state.get( 'selection' );
				ids.forEach( function ( id ) {
					var attachment = wp.media.attachment( id );
					attachment.fetch().done( function () {
						if ( selection ) {
							selection.add( attachment );
						}
					} );
				} );
				if ( frame.content ) {
					frame.content.mode( 'browse' );
				}
			} catch ( err ) {
				// The images are in the media library regardless.
			}
		},

		openDir: function ( e ) {
			e.preventDefault();
			this.load( $( e.currentTarget ).data( 'path' ), 1 );
		},

		goUp: function ( e ) {
			e.preventDefault();
			var parts = this.currentPath.replace( /\/+$/, '' ).split( '/' );
			parts.pop();
			var parent = parts.join( '/' ) || '/';
			this.load( parent, 1 );
		},

		importImage: function ( e ) {
			e.preventDefault();
			var self = this;
			var $btn = $( e.currentTarget );
			var $tile = $btn.closest( '.ncmb-tile' );
			var path = $tile.data( 'path' );

			$btn.prop( 'disabled', true ).text( NCMB.i18n.importing );

			this.request( '/import', { path: path }, 'POST' )
				.done( function ( res ) {
					$tile.addClass( 'ncmb-done' );
					$btn.text( '✔ ' + NCMB.i18n.imported );
					self.trigger( 'ncmb:imported' );
					self.selectInLibrary( res.id );
				} )
				.fail( function ( xhr ) {
					$btn.prop( 'disabled', false ).text( NCMB.i18n.import );
					window.alert(
						xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: NCMB.i18n.error
					);
				} );
		},

		/**
		 * After an import, switches to the dialog's media library view and
		 * selects the freshly imported attachment so it can be inserted or set
		 * as the featured image. Works in both the featured-image and the
		 * insert dialog.
		 */
		selectInLibrary: function ( attachmentId ) {
			var frame = this.controller;
			if ( ! frame || ! attachmentId ) {
				return;
			}
			try {
				var attachment = wp.media.attachment( attachmentId );
				attachment.fetch().done( function () {
					var state = frame.state();
					var selection = state && state.get( 'selection' );
					if ( selection ) {
						selection.add( attachment );
					}
				} );
				// Switch back to the top "Media Library" tab; the image is now a
				// normal attachment there and the toolbar action becomes active.
				if ( frame.content ) {
					frame.content.mode( 'browse' );
				}
			} catch ( err ) {
				// On error the image is in the media library regardless.
			}
		},
	} );

	/**
	 * Frame extension: register "Nextcloud" as a router tab (at the top, next to
	 * "Media Library") and render the associated content.
	 *
	 * The router approach works in every modal variant (featured image, image
	 * block, "Add Media"), because they all use the top tab router — unlike the
	 * left-hand menu, which the featured-image dialog, for example, does not have.
	 */
	function extendFrame( FrameConstructor ) {
		return FrameConstructor.extend( {
			bindHandlers: function () {
				FrameConstructor.prototype.bindHandlers.apply( this, arguments );
				this.on( 'content:render:ncmb', this.renderNextcloudContent, this );
			},

			browseRouter: function ( routerView ) {
				FrameConstructor.prototype.browseRouter.apply( this, arguments );
				routerView.set( {
					ncmb: {
						text: NCMB.i18n.tabTitle,
						priority: 60,
					},
				} );
			},

			renderNextcloudContent: function () {
				var view = new NextcloudBrowser( { controller: this } );
				this.content.set( view );
			},
		} );
	}

	if ( wp.media.view.MediaFrame.Post ) {
		wp.media.view.MediaFrame.Post = extendFrame( wp.media.view.MediaFrame.Post );
	}
	if ( wp.media.view.MediaFrame.Select ) {
		wp.media.view.MediaFrame.Select = extendFrame( wp.media.view.MediaFrame.Select );
	}

	/**
	 * Standalone integration for the media library screens ("Add New Media File"
	 * and the media library grid), which do not open a wp.media dialog. Adds an
	 * "Import from Nextcloud" button that opens the browser in its own modal.
	 * There is no insertion target here — it only imports.
	 */
	function openImporterModal() {
		var $overlay = $(
			'<div class="ncmb-modal-overlay">' +
				'<div class="ncmb-modal" role="dialog" aria-modal="true">' +
				'<div class="ncmb-modal-head">' +
				'<span class="ncmb-modal-title">' +
				escapeHtml( NCMB.i18n.importerTitle ) +
				'</span>' +
				'<button type="button" class="ncmb-modal-close" aria-label="' +
				escapeAttr( NCMB.i18n.close ) +
				'">×</button>' +
				'</div>' +
				'<div class="ncmb-modal-body"></div>' +
				'</div></div>'
		);
		$( 'body' ).append( $overlay );

		// Without a frame: selectInLibrary etc. become no-ops (via guards).
		var browser = new NextcloudBrowser( { controller: null } );
		$overlay.find( '.ncmb-modal-body' ).append( browser.$el );
		browser.render();

		var imported = false;
		browser.on( 'ncmb:imported', function () {
			imported = true;
		} );

		function close() {
			browser.off( 'ncmb:imported' );
			browser.remove();
			$overlay.remove();
			$( document ).off( 'keydown.ncmbmodal' );
			// After imports, refresh the media library grid so the new images appear.
			if ( imported && 'upload' === ( window.pagenow || '' ) ) {
				window.location.reload();
			}
		}

		$overlay.find( '.ncmb-modal-close' ).on( 'click', close );
		$overlay.on( 'click', function ( e ) {
			if ( e.target === $overlay[ 0 ] ) {
				close();
			}
		} );
		$( document ).on( 'keydown.ncmbmodal', function ( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		} );
	}

	$( function () {
		var page = window.pagenow || '';
		if ( 'media-new' !== page && 'upload' !== page ) {
			return;
		}

		var $btn = $( '<button type="button" class="page-title-action ncmb-open-importer"></button>' ).text(
			NCMB.i18n.openImporter
		);

		if ( 'upload' === page ) {
			var $existing = $( '.wrap > .page-title-action' );
			if ( $existing.length ) {
				$existing.last().after( $btn );
			} else {
				$( '.wrap .wp-header-end' ).first().before( $btn );
			}
		} else {
			// media-new: place below the heading.
			$btn.css( 'margin', '4px 0 16px' );
			var $h1 = $( '.wrap h1' ).first();
			if ( $h1.length ) {
				$h1.after( $btn );
			} else {
				$( '#wpbody-content .wrap' ).first().prepend( $btn );
			}
		}

		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			openImporterModal();
		} );
	} );

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			}[ c ];
		} );
	}

	function escapeAttr( str ) {
		return escapeHtml( str );
	}
} )( jQuery );
