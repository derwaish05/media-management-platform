<?php

declare(strict_types=1);

namespace MMP\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Gallery\GalleryRenderer;

// Guard: Bricks\Element must exist before PHP can parse the class declaration.
// The autoloader loads this file during the `init` hook; if Bricks is inactive
// the parent class is absent and PHP would fatal without this early return.
if ( ! class_exists( '\Bricks\Element' ) ) {
    return;
}

/**
 * Bricks Builder Element — MMP Gallery (S42).
 *
 * Extends \Bricks\Element to register an "MMP Gallery" element in the
 * Bricks Builder panel. Controls mirror the [mmp_gallery] shortcode options.
 *
 * Registration: the class is registered via \Bricks\Elements::register_element()
 * inside an `init` callback from Plugin::registerServices() — bails silently
 * if Bricks Builder is not active.
 *
 * @package MMP\Compat
 * @since   1.0.0
 */
class BricksGalleryElement extends \Bricks\Element {

    /** @var string */
    public $category = 'media';

    /** @var string */
    public $name = 'mmp-gallery';

    /** @var string */
    public $icon = 'ti-image';

    /** @var string */
    public $label = 'MMP Gallery';

    /** @var FolderRepository */
    private FolderRepository $folderRepository;

    // -------------------------------------------------------------------------
    // Static registration entry point
    // -------------------------------------------------------------------------

    /**
     * Register this element with Bricks.
     * Called on `init` after checking Bricks is active.
     */
    public static function register( FolderRepository $folderRepository ): void {
        if ( ! class_exists( '\Bricks\Elements' ) ) {
            return;
        }

        // Store the repository in a static property so the constructor can
        // access it (Bricks instantiates via class name without arguments).
        self::$sharedRepo = $folderRepository;

        \Bricks\Elements::register_element( __FILE__, 'mmp-gallery', self::class );
    }

    /** @var FolderRepository|null  Shared across instances (Bricks owns instantiation). */
    private static ?FolderRepository $sharedRepo = null;

    public function __construct( $element = [] ) {
        parent::__construct( $element );
        $this->folderRepository = self::$sharedRepo ?? new FolderRepository();
    }

    // -------------------------------------------------------------------------
    // Controls
    // -------------------------------------------------------------------------

    public function set_controls(): void {
        // ── Source ──────────────────────────────────────────────────────────
        $this->controls['folder_id'] = [
            'tab'         => 'content',
            'group'       => 'source',
            'label'       => esc_html__( 'Folder', 'media-management-platform'),
            'type'        => 'select',
            'options'     => $this->getFolderOptions(),
            'default'     => '0',
            'description' => esc_html__( 'Select the MMP folder to display.', 'media-management-platform'),
        ];

        // ── Layout ──────────────────────────────────────────────────────────
        $this->controls['layout'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Layout', 'media-management-platform'),
            'type'    => 'select',
            'options' => [
                'grid'     => esc_html__( 'Grid', 'media-management-platform'),
                'masonry'  => esc_html__( 'Masonry', 'media-management-platform'),
                'flex'     => esc_html__( 'Flex', 'media-management-platform'),
                'carousel' => esc_html__( 'Carousel', 'media-management-platform'),
            ],
            'default' => 'grid',
        ];

        $this->controls['columns'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Columns', 'media-management-platform'),
            'type'    => 'number',
            'min'     => 1,
            'max'     => 8,
            'step'    => 1,
            'default' => 3,
        ];

        $this->controls['gap'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Gap (px)', 'media-management-platform'),
            'type'    => 'number',
            'min'     => 0,
            'max'     => 64,
            'step'    => 1,
            'default' => 16,
        ];

        $this->controls['image_size'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__( 'Image Size', 'media-management-platform'),
            'type'    => 'select',
            'options' => [
                'thumbnail' => esc_html__( 'Thumbnail', 'media-management-platform'),
                'medium'    => esc_html__( 'Medium', 'media-management-platform'),
                'large'     => esc_html__( 'Large', 'media-management-platform'),
                'full'      => esc_html__( 'Full', 'media-management-platform'),
            ],
            'default' => 'medium',
        ];

        // ── Options ─────────────────────────────────────────────────────────
        $this->controls['lightbox'] = [
            'tab'     => 'content',
            'group'   => 'options',
            'label'   => esc_html__( 'Lightbox', 'media-management-platform'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['caption'] = [
            'tab'     => 'content',
            'group'   => 'options',
            'label'   => esc_html__( 'Show Caption', 'media-management-platform'),
            'type'    => 'checkbox',
            'default' => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Control groups
    // -------------------------------------------------------------------------

    public function set_control_groups(): void {
        $this->control_groups['source']  = [ 'title' => esc_html__( 'Source', 'media-management-platform') ];
        $this->control_groups['layout']  = [ 'title' => esc_html__( 'Layout', 'media-management-platform') ];
        $this->control_groups['options'] = [ 'title' => esc_html__( 'Options', 'media-management-platform') ];
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void {
        $settings = $this->settings;

        $renderer = new GalleryRenderer( $this->folderRepository );

        echo $renderer->render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered HTML from trusted internal GalleryRenderer
            'folderId'  => absint( $settings['folder_id'] ?? 0 ),
            'layout'    => sanitize_key( (string) ( $settings['layout']     ?? 'grid' ) ),
            'columns'   => (int) ( $settings['columns']   ?? 3 ),
            'gap'       => (int) ( $settings['gap']       ?? 16 ),
            'lightbox'  => (bool) ( $settings['lightbox'] ?? true ),
            'caption'   => (bool) ( $settings['caption']  ?? false ),
            'imageSize' => sanitize_key( (string) ( $settings['image_size'] ?? 'medium' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function getFolderOptions(): array {
        $options = [ '0' => esc_html__( '— Select folder —', 'media-management-platform') ];
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
