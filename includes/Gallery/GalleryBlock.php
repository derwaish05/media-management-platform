<?php

declare(strict_types=1);

namespace MMP\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;

/**
 * Registers the `mmp/gallery` Gutenberg block.
 *
 * The block is dynamic (server-side rendered). The edit component lives in
 * admin/assets/js/block-mmp-gallery.js and uses wp.* globals provided by the
 * Gutenberg runtime — no build step required.
 *
 * Frontend CSS is registered as a named style handle and is only output by
 * WordPress when the block is actually present on the rendered page.
 *
 * @package MMP\Gallery
 * @since   1.0.0
 */
class GalleryBlock {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Called once from Plugin::registerServices().
     */
    public function register(): void {
        add_action( 'init',                        [ $this, 'registerBlock' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorAssets' ] );
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    /**
     * Register the block type and the frontend style handle.
     *
     * The 'style' key registers a CSS handle that WordPress only enqueues when
     * this block appears on the rendered page.
     */
    public function registerBlock(): void {
        // Register frontend CSS (loaded only when block is present on page).
        wp_register_style(
            'mmp-gallery-block',
            MMP_URL . 'admin/assets/css/block-mmp-gallery.css',
            [],
            MMP_VERSION
        );

        // Register lightbox JS — enqueued lazily from renderBlock() when needed.
        wp_register_script(
            'mmp-gallery-lightbox',
            MMP_URL . 'admin/assets/js/mmp-gallery-lightbox.js',
            [],
            MMP_VERSION,
            true
        );

        // Register carousel JS — enqueued lazily from renderBlock() when needed.
        wp_register_script(
            'mmp-gallery-carousel',
            MMP_URL . 'admin/assets/js/mmp-gallery-carousel.js',
            [],
            MMP_VERSION,
            true
        );

        register_block_type(
            'mmp/gallery',
            [
                'api_version'     => 3,
                'title'           => __( 'MMP Gallery', 'media-management-platform'),
                'description'     => __( 'Display images from a Media Management Platform folder.', 'media-management-platform'),
                'category'        => 'media',
                'icon'            => 'format-gallery',
                'attributes'      => $this->blockAttributes(),
                'supports'        => [
                    'html'    => false,
                    'align'   => [ 'wide', 'full' ],
                    'spacing' => [ 'margin' => true, 'padding' => true ],
                ],
                'render_callback' => [ $this, 'renderBlock' ],
                'style'           => 'mmp-gallery-block',
            ]
        );
    }

    /**
     * Enqueue the block editor JS and editor-only CSS.
     *
     * Fires on `enqueue_block_editor_assets` so assets are available in the
     * Gutenberg editor but not on the frontend.
     */
    public function enqueueEditorAssets(): void {
        wp_enqueue_script(
            'mmp-gallery-block',
            MMP_URL . 'admin/assets/js/block-mmp-gallery.js',
            [
                'wp-blocks',
                'wp-element',
                'wp-block-editor',
                'wp-components',
                'wp-i18n',
                'wp-api-fetch',
            ],
            MMP_VERSION,
            true
        );

        wp_enqueue_style(
            'mmp-gallery-block-editor',
            MMP_URL . 'admin/assets/css/block-mmp-gallery-editor.css',
            [ 'wp-edit-blocks' ],
            MMP_VERSION
        );
    }

    /**
     * Server-side render callback — called by WordPress for each `mmp/gallery`
     * block instance on the page.
     *
     * @param  array<string, mixed> $attributes  Block attributes.
     * @return string  HTML output.
     */
    public function renderBlock( array $attributes ): string {
        // Enqueue lightbox JS when the block uses it (runs during content render,
        // so wp_enqueue_script will place it in wp_footer).
        if ( ! empty( $attributes['lightbox'] ) ) {
            wp_enqueue_script( 'mmp-gallery-lightbox' );
        }

        // Enqueue carousel JS when layout is carousel.
        if ( 'carousel' === ( $attributes['layout'] ?? '' ) ) {
            wp_enqueue_script( 'mmp-gallery-carousel' );
        }

        $renderer = new GalleryRenderer( $this->folderRepository );
        return $renderer->render( $attributes );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Attribute schema — must match the attributes object in block-mmp-gallery.js.
     *
     * @return array<string, array<string, mixed>>
     */
    private function blockAttributes(): array {
        return [
            'folderId'   => [ 'type' => 'integer', 'default' => 0 ],
            'folderName' => [ 'type' => 'string',  'default' => '' ],
            'layout'     => [
                'type'    => 'string',
                'default' => 'grid',
                'enum'    => [ 'grid', 'masonry', 'flex', 'carousel' ],
            ],
            'columns'    => [ 'type' => 'integer', 'default' => 3 ],
            'gap'        => [ 'type' => 'integer', 'default' => 16 ],
            'lightbox'   => [ 'type' => 'boolean', 'default' => true ],
            'caption'    => [ 'type' => 'boolean', 'default' => false ],
            'imageSize'  => [
                'type'    => 'string',
                'default' => 'medium',
                'enum'    => [ 'thumbnail', 'medium', 'large', 'full' ],
            ],
        ];
    }
}
