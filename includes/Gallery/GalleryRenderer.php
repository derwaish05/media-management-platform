<?php

declare(strict_types=1);

namespace MMP\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Taxonomy\FolderTaxonomy;

/**
 * Renders the HTML output for the `mmp/gallery` block.
 *
 * Called by GalleryBlock::renderBlock() on every page request that contains
 * the block. Fetches images via WP_Query (respects WP caching) and emits
 * accessible, lazy-loaded markup.
 *
 * Layout CSS classes:
 *   .mmp-gallery--grid     → CSS Grid (grid-template-columns)
 *   .mmp-gallery--masonry  → CSS columns (multi-column)
 *   .mmp-gallery--flex     → Flexbox
 *   .mmp-gallery--carousel → Sliding carousel (JS-powered)
 *
 * @package MMP\Gallery
 * @since   1.0.0
 */
class GalleryRenderer {

    private const ALLOWED_LAYOUTS    = [ 'grid', 'masonry', 'flex', 'carousel' ];
    private const ALLOWED_IMAGE_SIZES = [ 'thumbnail', 'medium', 'large', 'full' ];
    private const MAX_IMAGES         = 200;
    private const MAX_COLUMNS        = 8;
    private const MAX_GAP            = 64;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Render the block HTML from the given attribute map.
     *
     * @param  array<string, mixed> $attributes  Block attributes.
     * @return string  Safe HTML.
     */
    public function render( array $attributes ): string {
        $folderId  = (int) ( $attributes['folderId'] ?? 0 );
        $layout    = $this->sanitizeLayout( (string) ( $attributes['layout'] ?? 'grid' ) );
        $columns   = max( 1, min( self::MAX_COLUMNS, (int) ( $attributes['columns'] ?? 3 ) ) );
        $gap       = max( 0, min( self::MAX_GAP,     (int) ( $attributes['gap']     ?? 16 ) ) );
        $lightbox  = (bool) ( $attributes['lightbox'] ?? true );
        $imageSize = $this->sanitizeImageSize( (string) ( $attributes['imageSize'] ?? 'medium' ) );
        $caption   = (bool) ( $attributes['caption'] ?? false );

        if ( $folderId <= 0 ) {
            return $this->emptyState( __( 'No folder selected.', 'media-management-platform') );
        }

        $attachments = $this->getAttachments( $folderId );

        if ( empty( $attachments ) ) {
            return $this->emptyState( __( 'No images in this folder.', 'media-management-platform') );
        }

        if ( 'carousel' === $layout ) {
            return $this->buildCarouselHtml( $attachments, $columns, $gap, $lightbox, $imageSize, $caption );
        }

        return $this->buildHtml( $attachments, $layout, $columns, $gap, $lightbox, $imageSize, $caption );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch image attachments assigned to the given folder term.
     *
     * @param  int $folderId  Folder term ID.
     * @return \WP_Post[]
     */
    private function getAttachments( int $folderId ): array {
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => self::MAX_IMAGES,
            'no_found_rows'  => true,
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ],
        ] );

        return $query->posts;
    }

    /**
     * Build the gallery wrapper + item markup.
     *
     * CSS custom properties (--mmp-columns, --mmp-gap) are set as inline styles
     * on the wrapper so the stylesheet can respond to per-block values without
     * generating extra CSS classes.
     *
     * @param  \WP_Post[] $attachments
     */
    private function buildHtml(
        array $attachments,
        string $layout,
        int $columns,
        int $gap,
        bool $lightbox,
        string $imageSize,
        bool $caption = false
    ): string {
        $cssVars = sprintf(
            '--mmp-columns:%d;--mmp-gap:%dpx;',
            $columns,
            $gap
        );

        $html = sprintf(
            '<div class="mmp-gallery mmp-gallery--%s" style="%s">',
            esc_attr( $layout ),
            esc_attr( $cssVars )
        );

        foreach ( $attachments as $attachment ) {
            if ( ! ( $attachment instanceof \WP_Post ) ) {
                continue;
            }

            $imgSrc  = wp_get_attachment_image_url( $attachment->ID, $imageSize );
            $fullSrc = wp_get_attachment_url( $attachment->ID );

            if ( ! $imgSrc || ! $fullSrc ) {
                continue;
            }

            $alt         = (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
            $captionText = (string) $attachment->post_excerpt;

            if ( '' === $alt ) {
                $alt = $attachment->post_title;
            }

            $imgTag = sprintf(
                '<img src="%s" alt="%s" loading="lazy" decoding="async" class="mmp-gallery__img">',
                esc_url( $imgSrc ),
                esc_attr( $alt )
            );

            // Build inner content (image + optional caption).
            $inner = $imgTag;
            if ( $caption && '' !== $captionText ) {
                $inner .= sprintf(
                    '<figcaption class="mmp-gallery__caption">%s</figcaption>',
                    esc_html( $captionText )
                );
            }

            $itemTag   = $caption ? 'figure' : 'div';
            $itemClass = 'mmp-gallery__item' . ( $caption ? ' mmp-gallery__item--captioned' : '' );

            if ( $lightbox ) {
                $html .= sprintf(
                    '<a href="%s" class="%s mmp-gallery__item--lightbox" data-pswp-src="%s" data-gallery="mmp-gallery">%s</a>',
                    esc_url( $fullSrc ),
                    esc_attr( $itemClass ),
                    esc_url( $fullSrc ),
                    $inner
                );
            } else {
                $html .= sprintf(
                    '<%1$s class="%2$s">%3$s</%1$s>',
                    $itemTag,
                    esc_attr( $itemClass ),
                    $inner
                );
            }
        }

        $html .= '</div>';

        /**
         * Filters the rendered HTML of the mmp/gallery block.
         *
         * @since 1.0.0
         *
         * @param string     $html         Rendered gallery HTML.
         * @param \WP_Post[] $attachments  Image attachment posts.
         * @param string     $layout       Gallery layout slug.
         */
        return (string) apply_filters( 'mmp_gallery_html', $html, $attachments, $layout );
    }

    /**
     * Build the carousel wrapper + slide markup.
     *
     * Emits a structure the mmp-gallery-carousel.js script can drive:
     *   .mmp-gallery--carousel[data-mmp-carousel]
     *     .mmp-carousel__viewport
     *       .mmp-carousel__track
     *         .mmp-gallery__item  (one per image)
     *     .mmp-carousel__btn--prev
     *     .mmp-carousel__btn--next
     *     .mmp-carousel__dots
     *
     * @param  \WP_Post[] $attachments
     */
    private function buildCarouselHtml(
        array $attachments,
        int $columns,
        int $gap,
        bool $lightbox,
        string $imageSize,
        bool $caption = false
    ): string {
        $cssVars = sprintf( '--mmp-columns:%d;--mmp-gap:%dpx;', $columns, $gap );

        $html  = sprintf(
            '<div class="mmp-gallery mmp-gallery--carousel" style="%s" data-mmp-carousel="">',
            esc_attr( $cssVars )
        );
        $html .= '<div class="mmp-carousel__viewport">';
        $html .= '<div class="mmp-carousel__track">';

        $count = 0;
        foreach ( $attachments as $attachment ) {
            if ( ! ( $attachment instanceof \WP_Post ) ) {
                continue;
            }

            $imgSrc  = wp_get_attachment_image_url( $attachment->ID, $imageSize );
            $fullSrc = wp_get_attachment_url( $attachment->ID );

            if ( ! $imgSrc || ! $fullSrc ) {
                continue;
            }

            $alt         = (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
            $captionText = (string) $attachment->post_excerpt;

            if ( '' === $alt ) {
                $alt = $attachment->post_title;
            }

            $imgTag = sprintf(
                '<img src="%s" alt="%s" loading="lazy" decoding="async" class="mmp-gallery__img">',
                esc_url( $imgSrc ),
                esc_attr( $alt )
            );

            $inner = $imgTag;
            if ( $caption && '' !== $captionText ) {
                $inner .= sprintf(
                    '<figcaption class="mmp-gallery__caption">%s</figcaption>',
                    esc_html( $captionText )
                );
            }

            $itemTag   = $caption ? 'figure' : 'a';
            $itemClass = 'mmp-gallery__item mmp-gallery__item--slide' . ( $caption ? ' mmp-gallery__item--captioned' : '' );

            if ( $lightbox ) {
                $html .= sprintf(
                    '<a href="%s" class="%s mmp-gallery__item--lightbox" data-pswp-src="%s" data-gallery="mmp-gallery">%s</a>',
                    esc_url( $fullSrc ),
                    esc_attr( $itemClass ),
                    esc_url( $fullSrc ),
                    $inner
                );
            } else {
                $html .= sprintf(
                    '<%1$s class="%2$s">%3$s</%1$s>',
                    $itemTag,
                    esc_attr( $itemClass ),
                    $inner
                );
            }

            $count++;
        }

        $html .= '</div>';  // .mmp-carousel__track
        $html .= '</div>';  // .mmp-carousel__viewport

        // Navigation buttons.
        $html .= '<button type="button" class="mmp-carousel__btn mmp-carousel__btn--prev" aria-label="' . esc_attr__( 'Previous slide', 'media-management-platform') . '">&#10094;</button>';
        $html .= '<button type="button" class="mmp-carousel__btn mmp-carousel__btn--next" aria-label="' . esc_attr__( 'Next slide', 'media-management-platform') . '">&#10095;</button>';

        // Dot indicators.
        if ( $count > 0 ) {
            $pageCount = (int) ceil( $count / max( 1, $columns ) );
            $html .= '<div class="mmp-carousel__dots" aria-hidden="true">';
            for ( $i = 0; $i < $pageCount; $i++ ) {
                $active  = 0 === $i ? ' mmp-carousel__dot--active' : '';
                $html .= sprintf(
                    '<button type="button" class="mmp-carousel__dot%s" aria-label="%s"></button>',
                    esc_attr( $active ),
                    /* translators: %1$d: slide number, %2$d: total slides */
                    esc_attr( sprintf( __( 'Slide %1$d of %2$d', 'media-management-platform'), $i + 1, $pageCount ) )
                );
            }
            $html .= '</div>';
        }

        $html .= '</div>';  // .mmp-gallery--carousel

        return (string) apply_filters( 'mmp_gallery_html', $html, $attachments, 'carousel' );
    }

    /**
     * @param string $layout Raw layout value from attributes.
     */
    private function sanitizeLayout( string $layout ): string {
        return in_array( $layout, self::ALLOWED_LAYOUTS, true ) ? $layout : 'grid';
    }

    /**
     * @param string $size Raw image size value from attributes.
     */
    private function sanitizeImageSize( string $size ): string {
        return in_array( $size, self::ALLOWED_IMAGE_SIZES, true ) ? $size : 'medium';
    }

    /**
     * Returns the empty-state paragraph shown when no folder or images found.
     */
    private function emptyState( string $message ): string {
        return '<p class="mmp-gallery-empty">' . esc_html( $message ) . '</p>';
    }
}
