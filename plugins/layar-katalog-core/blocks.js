( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var contextualBlocks = [
		{
			name: 'layar-katalog/breadcrumbs',
			title: 'Breadcrumb Katalog',
			icon: 'list-view',
			message: 'Jejak navigasi katalog/episode akan dibuat otomatis.'
		},
		{
			name: 'layar-katalog/catalog-details',
			title: 'Metadata Katalog',
			icon: 'info-outline',
			message: 'Metadata akan diambil dari item katalog yang sedang ditampilkan.'
		},
		{
			name: 'layar-katalog/episode-list',
			title: 'Daftar Episode',
			icon: 'list-view',
			message: 'Daftar episode akan diambil dari item katalog yang sedang ditampilkan.'
		},
		{
			name: 'layar-katalog/episode-info',
			title: 'Info Episode',
			icon: 'info-outline',
			message: 'Info musim, nomor, durasi, dan tautan induk akan ditampilkan di halaman episode.'
		},
		{
			name: 'layar-katalog/video-player',
			title: 'Pemutar Video',
			icon: 'format-video',
			message: 'Player menggunakan URL MP4/WebM yang disimpan pada episode.'
		},
		{
			name: 'layar-katalog/featured-title',
			title: 'Pilihan Editor',
			icon: 'star-filled',
			message: 'Pilihan editor yang ditandai akan tampil di sini; jika belum ada, item terbaru digunakan.'
		},
		{
			name: 'layar-katalog/catalog-filters',
			title: 'Filter Katalog',
			icon: 'filter',
			message: 'Form filter katalog akan menampilkan genre, format, tahun, status, kata kunci, dan urutan.'
		},
		{
			name: 'layar-katalog/genre-links',
			title: 'Jelajah Genre',
			icon: 'tag',
			message: 'Genre yang memiliki item katalog akan ditautkan otomatis.'
		},
		{
			name: 'layar-katalog/search-result-details',
			title: 'Konteks Hasil Pencarian',
			icon: 'search',
			message: 'Metadata katalog atau info episode akan ditampilkan sesuai jenis hasil.'
		},
		{
			name: 'layar-katalog/related-titles',
			title: 'Judul Terkait',
			icon: 'screenoptions',
			message: 'Judul yang berbagi genre dengan item ini akan ditampilkan otomatis.'
		},
		{
			name: 'layar-katalog/episode-navigation',
			title: 'Navigasi Episode',
			icon: 'controls-play',
			message: 'Tautan episode sebelumnya/berikutnya dan seri induk akan dibuat otomatis.'
		}
	];

	contextualBlocks.forEach( function ( block ) {
		wp.blocks.registerBlockType( block.name, {
			apiVersion: 3,
			title: block.title,
			icon: block.icon,
			category: 'widgets',
			usesContext: [ 'postId', 'postType' ],
			supports: { html: false, inserter: false },
			edit: function () {
				return el(
					'div',
					{ className: 'lkc-editor-placeholder' },
					el( 'strong', {}, block.title ),
					el( 'p', {}, block.message )
				);
			},
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp );
