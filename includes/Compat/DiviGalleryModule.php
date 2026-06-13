<?php

declare(strict_types=1);

namespace MMP\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Gallery\GalleryRenderer;

/**
 * Divi Builder Module — MMP Gallery (S42).
 *
 * Extends ET_Builder_Module to register an "MMP Gallery" module inside the
 * Divi page builder. Settings mirror the [mmp_gallery] shortcode options.
 *
 * Registration: class is loaded inside an `et_builder_ready` callback from
 * Plugin::registerServices() — bails silently if Divi is not active.
 *
 * @package MMP\Compat
 * @since   1.0.0
 */
class DiviGalleryModule extends \ET_Builder_Module {

    /** @var string */
    public $slug = 'et_pb_mmp_gallery';

    /** @var string */
    public $vb_support = 'on';

    /** @var FolderRepository */
    private FolderRepository $folderRepository;

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    public function init(): void {
        $this->folderRepository = new FolderRepository();

        $this->name             = __( 'MMP Gallery', 'media-management-platform');
        $this->main_css_element = '%%order_class%%';

        $this->settings_modal_toggles = [
            'general' => [
                'toggles' => [
                    'source'  => __( 'Source', 'media-management-platform'),
                    'layout'  => __( 'Layout', 'media-management-platform'),
                    'options' => __( 'Options', 'media-management-platform'),
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Fields (settings)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array {
        return [
            'folder_id' => [
                'label'           => __( 'Folder', 'media-management-platform'),
                'type'            => 'select',
                'options'         => $this->getFolderOptions(),
                'default'         => '0',
                'toggle_slug'     => 'source',
                'description'     => __( 'Select the MMP folder to display as a gallery.', 'media-management-platform'),
            ],
            'layout' => [
                'label'           => __( 'Layout', 'media-management-platform'),
                'type'            => 'select',
                'options'         => [
                    'grid'     => __( 'Grid', 'media-management-platform'),
                    'masonry'  => __( 'Masonry', 'media-management-platform'),
                    'flex'     => __( 'Flex', 'media-management-platform'),
                    'carousel' => __( 'Carousel', 'media-management-platform'),
                ],
                'default'         => 'grid',
                'toggle_slug'     => 'layout',
            ],
            'columns' => [
                'label'           => __( 'Columns', 'media-management-platform'),
                'type'            => 'range',
                'default'         => '3',
                'range_settings'  => [
                    'min'  => 1,
                    'max'  => 8,
                    'step' => 1,
                ],
                'unitless'        => true,
                'toggle_slug'     => 'layout',
            ],
            'gap' => [
                'label'           => __( 'Gap (px)', 'media-management-platform'),
                'type'            => 'range',
                'default'         => '16',
                'range_settings'  => [
                    'min'  => 0,
                    'max'  => 64,
                    'step' => 1,
                ],
                'unitless'        => true,
                'toggle_slug'     => 'layout',
            ],
            'image_size' => [
                'label'           => __( 'Image Size', 'media-management-platform'),
                'type'            => 'select',
                'options'         => [
                    'thumbnail' => __( 'Thumbnail', 'media-management-platform'),
                    'medium'    => __( 'Medium', 'media-management-platform'),
                    'large'     => __( 'Large', 'media-management-platform'),
                    'full'      => __( 'Full', 'media-management-platform'),
                ],
                'default'         => 'medium',
                'toggle_slug'     => 'layout',
            ],
            'lightbox' => [
                'label'           => __( 'Enable Lightbox', 'media-management-platform'),
                'type'            => 'yes_no_button',
                'options'         => [
                    'on'  => __( 'Yes', 'media-management-platform'),
                    'off' => __( 'No', 'media-management-platform'),
                ],
                'default'         => 'on',
                'toggle_slug'     => 'options',
            ],
            'caption' => [
                'label'           => __( 'Show Caption', 'media-management-platform'),
                'type'            => 'yes_no_button',
                'options'         => [
                    'on'  => __( 'Yes', 'media-management-platform'),
                    'off' => __( 'No', 'media-management-platform'),
                ],
                'default'         => 'off',
                'toggle_slug'     => 'options',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $attrs
     * @param  string|null          $content
     * @param  string               $renderSlug
     * @return string
     */
    public function render( $attrs, $content, $renderSlug ): string {
        $renderer = new GalleryRenderer( $this->folderRepository );

        return $renderer->render( [
            'folderId'  => absint( $this->props['folder_id'] ?? 0 ),
            'layout'    => sanitize_key( (string) ( $this->props['layout']     ?? 'grid' ) ),
            'columns'   => (int) ( $this->props['columns']   ?? 3 ),
            'gap'       => (int) ( $this->props['gap']       ?? 16 ),
            'lightbox'  => ( $this->props['lightbox'] ?? 'on' ) === 'on',
            'caption'   => ( $this->props['caption']  ?? 'off' ) === 'on',
            'imageSize' => sanitize_key( (string) ( $this->props['image_size'] ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ '0' => __( '— Select folder —', 'media-management-platform') ];
        $tree    = $this->folderRepository->getTree( 0 );
        $this->flattenToOptions( $tree, $options, 0 );
        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $options
     * @param int                              $depth
     */
    private function flattenToOptions( array $nodes, array &$options, int $depth ): void {
        foreach ( $nodes as $node ) {
            $id   = (string) ( $node['id']   ?? 0 );
            $name = (string) ( $node['name'] ?? '' );
            $options[ $id ] = str_repeat( '— ', $depth ) . $name;
            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $this->flattenToOptions( $node['children'], $options, $depth + 1 );
            }
        }
    }
}
