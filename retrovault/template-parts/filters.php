<?php
/**
 * شريط الفلترة. نموذج GET عادي (يعمل بدون JavaScript)، والسكربت يحدّث النتائج دون إعادة تحميل.
 * على الجوال تُطوى الفلاتر الأربع خلف زر «الفلاتر» ويبقى البحث والترتيب ظاهرين.
 *
 * @package RetroVault
 *
 * @var array $args filters.
 */

defined( 'ABSPATH' ) || exit;

$rvt_f       = isset( $args['filters'] ) ? $args['filters'] : rv_current_filters();
$rvt_systems = rv_get_systems( true );
$rvt_genres  = get_terms(
	array(
		'taxonomy'   => 'rv_genre',
		'hide_empty' => true,
	)
);
$rvt_genres  = is_wp_error( $rvt_genres ) ? array() : $rvt_genres;
$rvt_count   = rvt_active_filters( $rvt_f );

list( $rvt_action, $rvt_hidden ) = rvt_form_action( rvt_library_url() );
?>
<form class="filters" method="get" action="<?php echo esc_url( $rvt_action ); ?>" data-filters>
	<?php echo $rvt_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<div class="field field--q">
		<label for="f-q"><?php esc_html_e( 'بحث', 'retrovault' ); ?></label>
		<div class="search-form">
			<input class="input search-form__input" type="search" id="f-q" name="q" value="<?php echo esc_attr( $rvt_f['q'] ); ?>" placeholder="<?php esc_attr_e( 'اسم اللعبة أو كلمة من وصفها', 'retrovault' ); ?>" autocomplete="off">
			<button type="submit" class="search-form__btn"><?php echo rvt_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'عرض النتائج', 'retrovault' ); ?></span></button>
		</div>
	</div>

	<div class="field field--sort">
		<label for="f-sort"><?php esc_html_e( 'الترتيب', 'retrovault' ); ?></label>
		<select class="select" id="f-sort" name="sort">
			<?php foreach ( rv_sort_options() as $rvt_key => $rvt_label ) : ?>
				<option value="<?php echo esc_attr( $rvt_key ); ?>" <?php selected( $rvt_f['sort'], $rvt_key ); ?>><?php echo esc_html( $rvt_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<button type="button" class="btn btn--ghost filters__toggle" data-filters-toggle aria-expanded="false" aria-controls="f-more">
		<?php echo rvt_icon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php esc_html_e( 'الفلاتر', 'retrovault' ); ?>
		<span class="filters__count" data-filters-count<?php echo $rvt_count ? '' : ' hidden'; ?>><?php echo esc_html( number_format_i18n( $rvt_count ) ); ?></span>
	</button>

	<div class="filters__more" id="f-more">
		<div class="field">
			<label for="f-system"><?php esc_html_e( 'النظام', 'retrovault' ); ?></label>
			<select class="select" id="f-system" name="system">
				<option value=""><?php esc_html_e( 'كل الأنظمة', 'retrovault' ); ?></option>
				<?php foreach ( $rvt_systems as $rvt_system ) : ?>
					<option value="<?php echo esc_attr( $rvt_system['slug'] ); ?>" <?php selected( $rvt_f['system'], $rvt_system['slug'] ); ?>><?php echo esc_html( $rvt_system['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="field">
			<label for="f-genre"><?php esc_html_e( 'النوع', 'retrovault' ); ?></label>
			<select class="select" id="f-genre" name="genre">
				<option value=""><?php esc_html_e( 'كل الأنواع', 'retrovault' ); ?></option>
				<?php foreach ( $rvt_genres as $rvt_genre ) : ?>
					<option value="<?php echo esc_attr( $rvt_genre->slug ); ?>" <?php selected( $rvt_f['genre'], $rvt_genre->slug ); ?>><?php echo esc_html( $rvt_genre->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="field">
			<label for="f-players"><?php esc_html_e( 'اللاعبون', 'retrovault' ); ?></label>
			<select class="select" id="f-players" name="players">
				<option value=""><?php esc_html_e( 'الكل', 'retrovault' ); ?></option>
				<option value="single" <?php selected( $rvt_f['players'], 'single' ); ?>><?php esc_html_e( 'لاعب واحد', 'retrovault' ); ?></option>
				<option value="multi" <?php selected( $rvt_f['players'], 'multi' ); ?>><?php esc_html_e( 'أكثر من لاعب', 'retrovault' ); ?></option>
			</select>
		</div>

		<div class="field">
			<label for="f-status"><?php esc_html_e( 'الحالة', 'retrovault' ); ?></label>
			<select class="select" id="f-status" name="status">
				<option value=""><?php esc_html_e( 'الكل', 'retrovault' ); ?></option>
				<?php foreach ( rv_status_options() as $rvt_key => $rvt_label ) : ?>
					<option value="<?php echo esc_attr( $rvt_key ); ?>" <?php selected( $rvt_f['status'], $rvt_key ); ?>><?php echo esc_html( $rvt_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<?php /* بدون JavaScript لا تُطبَّق القوائم تلقائياً؛ السكربت يخفي هذا الزر. */ ?>
		<div class="filters__submit">
			<button type="submit" class="btn btn--pill btn--sm"><?php esc_html_e( 'عرض النتائج', 'retrovault' ); ?></button>
		</div>
	</div>
</form>
