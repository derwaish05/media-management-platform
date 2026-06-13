<?php

declare(strict_types=1);

namespace MMP\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Folder\FolderService;
use MMP\Taxonomy\FolderTaxonomy;

/**
 * WPGraphQL extension — MMP GraphQL API (S43).
 *
 * Registers custom types, queries, and mutations on the `graphql_register_types`
 * action hook, which WPGraphQL fires after its own core types are in place.
 *
 * Bails silently (no-op) if WPGraphQL is not active.
 *
 * Types registered:
 *   MmpFolder          — folder node with children and nested files.
 *   MmpFile            — attachment metadata shape.
 *   MmpFileConnection  — paginated list of MmpFile nodes.
 *   MmpPageInfo        — pagination metadata.
 *   Mutation payloads  — MmpCreateFolderPayload, MmpMoveFolderPayload,
 *                        MmpDeleteFolderPayload, MmpAssignFilePayload.
 *
 * Queries:
 *   mmpFolders(userId: Int)  — full folder tree.
 *   mmpFolder(id: ID!)       — single folder with children and files.
 *
 * Mutations:
 *   mmpCreateFolder(name, parentId, userId)
 *   mmpMoveFolder(id, newParentId)
 *   mmpDeleteFolder(id, recursive)
 *   mmpAssignFile(fileId, folderId)
 *
 * @package MMP\API
 * @since   1.0.0
 */
class GraphQLSchema {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderService    $folderService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( ! function_exists( 'register_graphql_object_type' ) ) {
            return; // WPGraphQL not active.
        }

        add_action( 'graphql_register_types', [ $this, 'registerTypes' ] );
    }

    // -------------------------------------------------------------------------
    // Types, queries and mutations
    // -------------------------------------------------------------------------

    public function registerTypes(): void {
        $this->registerMmpPageInfoType();
        $this->registerMmpFileType();
        $this->registerMmpFileConnectionType();
        $this->registerMmpFolderType();
        $this->registerMutationPayloadTypes();
        $this->registerQueries();
        $this->registerMutations();
    }

    // -------------------------------------------------------------------------
    // Type definitions
    // -------------------------------------------------------------------------

    private function registerMmpPageInfoType(): void {
        register_graphql_object_type( 'MmpPageInfo', [
            'description' => __( 'Pagination metadata for an MMP file list.', 'media-management-platform'),
            'fields'      => [
                'total'       => [
                    'type'        => 'Int',
                    'description' => __( 'Total number of files matching the query.', 'media-management-platform'),
                ],
                'hasNextPage' => [
                    'type'        => 'Boolean',
                    'description' => __( 'Whether more files exist after the current page.', 'media-management-platform'),
                ],
            ],
        ] );
    }

    private function registerMmpFileType(): void {
        register_graphql_object_type( 'MmpFile', [
            'description' => __( 'A WordPress media attachment inside an MMP folder.', 'media-management-platform'),
            'fields'      => [
                'id'         => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Attachment post ID.', 'media-management-platform'),
                ],
                'databaseId' => [
                    'type'        => 'Int',
                    'description' => __( 'Numeric attachment post ID.', 'media-management-platform'),
                ],
                'title'      => [
                    'type'        => 'String',
                    'description' => __( 'Attachment title (post_title).', 'media-management-platform'),
                ],
                'url'        => [
                    'type'        => 'String',
                    'description' => __( 'Direct URL to the attachment file.', 'media-management-platform'),
                ],
                'mimeType'   => [
                    'type'        => 'String',
                    'description' => __( 'MIME type, e.g. "image/jpeg".', 'media-management-platform'),
                ],
                'fileSize'   => [
                    'type'        => 'Int',
                    'description' => __( 'File size in bytes.', 'media-management-platform'),
                ],
                'date'       => [
                    'type'        => 'String',
                    'description' => __( 'Upload date (Y-m-d).', 'media-management-platform'),
                ],
                'altText'    => [
                    'type'        => 'String',
                    'description' => __( 'Image ALT text (_wp_attachment_image_alt).', 'media-management-platform'),
                ],
            ],
        ] );
    }

    private function registerMmpFileConnectionType(): void {
        register_graphql_object_type( 'MmpFileConnection', [
            'description' => __( 'Paginated list of MmpFile nodes.', 'media-management-platform'),
            'fields'      => [
                'nodes'    => [
                    'type'        => [ 'list_of' => 'MmpFile' ],
                    'description' => __( 'The file nodes on this page.', 'media-management-platform'),
                ],
                'pageInfo' => [
                    'type'        => 'MmpPageInfo',
                    'description' => __( 'Pagination metadata.', 'media-management-platform'),
                ],
            ],
        ] );
    }

    private function registerMmpFolderType(): void {
        register_graphql_object_type( 'MmpFolder', [
            'description' => __( 'An MMP folder (mmp_folder taxonomy term).', 'media-management-platform'),
            'fields'      => [
                'id'       => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Folder term ID.', 'media-management-platform'),
                ],
                'termId'   => [
                    'type'        => 'Int',
                    'description' => __( 'Numeric folder term ID.', 'media-management-platform'),
                ],
                'name'     => [
                    'type'        => 'String',
                    'description' => __( 'Folder name.', 'media-management-platform'),
                ],
                'slug'     => [
                    'type'        => 'String',
                    'description' => __( 'Folder slug.', 'media-management-platform'),
                ],
                'parent'   => [
                    'type'        => 'Int',
                    'description' => __( 'Parent folder term ID (0 = root).', 'media-management-platform'),
                ],
                'color'    => [
                    'type'        => 'String',
                    'description' => __( 'Folder colour hex (mmp_folder_color meta).', 'media-management-platform'),
                ],
                'count'    => [
                    'type'        => 'Int',
                    'description' => __( 'Number of files directly in this folder.', 'media-management-platform'),
                ],
                'children' => [
                    'type'        => [ 'list_of' => 'MmpFolder' ],
                    'description' => __( 'Direct child folders.', 'media-management-platform'),
                    'resolve'     => function ( array $folder ): array {
                        $children = $this->folderRepository->getChildren( (int) $folder['termId'] );
                        return array_map( [ $this, 'normaliseFolderNode' ], $children );
                    },
                ],
                'files'    => [
                    'type'        => 'MmpFileConnection',
                    'description' => __( 'Paginated files in this folder.', 'media-management-platform'),
                    'args'        => [
                        'first'  => [
                            'type'        => 'Int',
                            'description' => __( 'Number of files to return (max 100).', 'media-management-platform'),
                        ],
                        'after'  => [
                            'type'        => 'String',
                            'description' => __( 'Offset cursor (numeric page index, 1-based).', 'media-management-platform'),
                        ],
                        'search' => [
                            'type'        => 'String',
                            'description' => __( 'Search term to filter files by title.', 'media-management-platform'),
                        ],
                    ],
                    'resolve'     => function ( array $folder, array $args ): array {
                        return $this->resolveFiles( (int) $folder['termId'], $args );
                    },
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Mutation payload types
    // -------------------------------------------------------------------------

    private function registerMutationPayloadTypes(): void {
        register_graphql_object_type( 'MmpCreateFolderPayload', [
            'description' => __( 'Payload returned after mmpCreateFolder.', 'media-management-platform'),
            'fields'      => [
                'folder'  => [ 'type' => 'MmpFolder', 'description' => __( 'The newly created folder.', 'media-management-platform') ],
                'success' => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MmpMoveFolderPayload', [
            'description' => __( 'Payload returned after mmpMoveFolder.', 'media-management-platform'),
            'fields'      => [
                'folder'  => [ 'type' => 'MmpFolder', 'description' => __( 'The moved folder with its new parent.', 'media-management-platform') ],
                'success' => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MmpDeleteFolderPayload', [
            'description' => __( 'Payload returned after mmpDeleteFolder.', 'media-management-platform'),
            'fields'      => [
                'deletedId' => [ 'type' => 'ID',      'description' => __( 'The term ID of the deleted folder.', 'media-management-platform') ],
                'success'   => [ 'type' => 'Boolean' ],
            ],
        ] );

        register_graphql_object_type( 'MmpAssignFilePayload', [
            'description' => __( 'Payload returned after mmpAssignFile.', 'media-management-platform'),
            'fields'      => [
                'fileId'   => [ 'type' => 'ID',      'description' => __( 'The assigned attachment ID.', 'media-management-platform') ],
                'folderId' => [ 'type' => 'ID',      'description' => __( 'The target folder term ID.', 'media-management-platform') ],
                'success'  => [ 'type' => 'Boolean' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    private function registerQueries(): void {
        // mmpFolders — full folder tree.
        register_graphql_field( 'RootQuery', 'mmpFolders', [
            'type'        => [ 'list_of' => 'MmpFolder' ],
            'description' => __( 'List all MMP folders as a nested tree.', 'media-management-platform'),
            'args'        => [
                'userId' => [
                    'type'        => 'Int',
                    'description' => __( 'Filter to folders owned by a specific user (0 = global).', 'media-management-platform'),
                ],
            ],
            'resolve'     => function ( $root, array $args ): array {
                $this->requireCap( 'upload_files' );

                $userId = isset( $args['userId'] ) ? (int) $args['userId'] : 0;
                $tree   = $this->folderRepository->getTree( $userId );
                return $this->normaliseFolderTree( $tree );
            },
        ] );

        // mmpFolder — single folder by ID.
        register_graphql_field( 'RootQuery', 'mmpFolder', [
            'type'        => 'MmpFolder',
            'description' => __( 'Retrieve a single MMP folder by its term ID.', 'media-management-platform'),
            'args'        => [
                'id' => [
                    'type'        => [ 'non_null' => 'ID' ],
                    'description' => __( 'Folder term ID.', 'media-management-platform'),
                ],
            ],
            'resolve'     => function ( $root, array $args ): ?array {
                $this->requireCap( 'upload_files' );

                $folder = $this->folderRepository->getById( absint( $args['id'] ) );
                return $folder ? $this->normaliseFolderNode( $folder ) : null;
            },
        ] );
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    private function registerMutations(): void {
        // mmpCreateFolder
        register_graphql_mutation( 'mmpCreateFolder', [
            'inputFields'         => [
                'name'     => [ 'type' => [ 'non_null' => 'String' ], 'description' => __( 'Folder name.', 'media-management-platform') ],
                'parentId' => [ 'type' => 'Int', 'description' => __( 'Parent folder term ID (0 = top-level).', 'media-management-platform') ],
                'userId'   => [ 'type' => 'Int', 'description' => __( 'Owner user ID (0 = global).', 'media-management-platform') ],
            ],
            'outputFields'        => [
                'folder'  => [ 'type' => 'MmpFolder' ],
                'success' => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mmp_folders' );

                $name     = (string) ( $input['name']     ?? '' );
                $parentId = (int)    ( $input['parentId'] ?? 0 );
                $userId   = (int)    ( $input['userId']   ?? get_current_user_id() );

                try {
                    $termId = $this->folderService->createFolder( $name, $parentId, $userId );
                    $folder = $this->folderRepository->getById( $termId );
                    return [
                        'folder'  => $folder ? $this->normaliseFolderNode( $folder ) : null,
                        'success' => true,
                    ];
                } catch ( \Exception $e ) {
                    throw new \GraphQL\Error\UserError( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            },
        ] );

        // mmpMoveFolder
        register_graphql_mutation( 'mmpMoveFolder', [
            'inputFields'         => [
                'id'          => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Folder term ID to move.', 'media-management-platform') ],
                'newParentId' => [ 'type' => [ 'non_null' => 'Int' ], 'description' => __( 'Target parent term ID (0 = top-level).', 'media-management-platform') ],
            ],
            'outputFields'        => [
                'folder'  => [ 'type' => 'MmpFolder' ],
                'success' => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mmp_folders' );

                $termId      = absint( $input['id'] );
                $newParentId = (int) ( $input['newParentId'] ?? 0 );

                try {
                    $success = $this->folderService->moveFolder( $termId, $newParentId );
                    $folder  = $this->folderRepository->getById( $termId );
                    return [
                        'folder'  => $folder ? $this->normaliseFolderNode( $folder ) : null,
                        'success' => $success,
                    ];
                } catch ( \Exception $e ) {
                    throw new \GraphQL\Error\UserError( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
            },
        ] );

        // mmpDeleteFolder
        register_graphql_mutation( 'mmpDeleteFolder', [
            'inputFields'         => [
                'id'        => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Folder term ID to delete.', 'media-management-platform') ],
                'recursive' => [ 'type' => 'Boolean', 'description' => __( 'Delete child folders recursively (default false).', 'media-management-platform') ],
            ],
            'outputFields'        => [
                'deletedId' => [ 'type' => 'ID' ],
                'success'   => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'manage_mmp_folders' );

                $termId    = absint( $input['id'] );
                $recursive = (bool) ( $input['recursive'] ?? false );

                $success = $this->folderService->deleteFolder( $termId, $recursive );
                return [
                    'deletedId' => $termId,
                    'success'   => $success,
                ];
            },
        ] );

        // mmpAssignFile
        register_graphql_mutation( 'mmpAssignFile', [
            'inputFields'         => [
                'fileId'   => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Attachment post ID.', 'media-management-platform') ],
                'folderId' => [ 'type' => [ 'non_null' => 'ID' ], 'description' => __( 'Target folder term ID.', 'media-management-platform') ],
            ],
            'outputFields'        => [
                'fileId'   => [ 'type' => 'ID' ],
                'folderId' => [ 'type' => 'ID' ],
                'success'  => [ 'type' => 'Boolean' ],
            ],
            'mutateAndGetPayload' => function ( array $input ): array {
                $this->requireCap( 'upload_files' );

                $fileId   = absint( $input['fileId'] );
                $folderId = absint( $input['folderId'] );

                if ( ! current_user_can( 'edit_post', $fileId ) ) {
                    throw new \GraphQL\Error\UserError( __( 'You are not allowed to edit this file.', 'media-management-platform') ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                $result = wp_set_object_terms( $fileId, [ $folderId ], FolderTaxonomy::TAXONOMY );

                if ( is_wp_error( $result ) ) {
                    throw new \GraphQL\Error\UserError( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                return [
                    'fileId'   => $fileId,
                    'folderId' => $folderId,
                    'success'  => true,
                ];
            },
        ] );
    }

    // -------------------------------------------------------------------------
    // Resolvers
    // -------------------------------------------------------------------------

    /**
     * Resolve the `files` field on an MmpFolder node.
     *
     * @param  int   $folderId
     * @param  array<string, mixed> $args  GraphQL field arguments.
     * @return array{ nodes: array<int, array<string, mixed>>, pageInfo: array<string, mixed> }
     */
    private function resolveFiles( int $folderId, array $args ): array {
        $perPage = min( 100, max( 1, (int) ( $args['first'] ?? 20 ) ) );
        $page    = max( 1, (int) ( $args['after'] ?? 1 ) );
        $search  = isset( $args['search'] ) ? (string) $args['search'] : '';

        $queryArgs = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            'orderby'        => 'post_title',
            'order'          => 'ASC',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => [ $folderId ],
                    'operator' => 'IN',
                ],
            ],
        ];

        if ( '' !== $search ) {
            $queryArgs['s'] = $search;
        }

        $query = new \WP_Query( $queryArgs );
        $nodes = [];

        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof \WP_Post ) ) {
                continue;
            }
            $nodes[] = $this->normaliseFile( $post );
        }

        $total   = (int) $query->found_posts;
        $pages   = (int) $query->max_num_pages;

        return [
            'nodes'    => $nodes,
            'pageInfo' => [
                'total'       => $total,
                'hasNextPage' => $page < $pages,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Data normalisers
    // -------------------------------------------------------------------------

    /**
     * Normalise a raw folder node (from FolderRepository) to the GraphQL shape.
     *
     * @param  array<string, mixed> $folder
     * @return array<string, mixed>
     */
    private function normaliseFolderNode( array $folder ): array {
        return [
            'id'       => (string) ( $folder['id']      ?? 0 ),
            'termId'   => (int)    ( $folder['id']      ?? 0 ),
            'name'     => (string) ( $folder['name']    ?? '' ),
            'slug'     => (string) ( $folder['slug']    ?? '' ),
            'parent'   => (int)    ( $folder['parent']  ?? 0 ),
            'color'    => (string) ( $folder['color']   ?? '#94a3b8' ),
            'count'    => (int)    ( $folder['count']   ?? 0 ),
            'children' => [], // resolved lazily by the `children` field resolver
        ];
    }

    /**
     * Recursively normalise an entire folder tree.
     *
     * @param  array<int, array<string, mixed>> $tree
     * @return array<int, array<string, mixed>>
     */
    private function normaliseFolderTree( array $tree ): array {
        $result = [];
        foreach ( $tree as $node ) {
            $normalised             = $this->normaliseFolderNode( $node );
            $normalised['children'] = $this->normaliseFolderTree( (array) ( $node['children'] ?? [] ) );
            $result[]               = $normalised;
        }
        return $result;
    }

    /**
     * Normalise a WP_Post attachment into the MmpFile GraphQL shape.
     *
     * @param  \WP_Post $post
     * @return array<string, mixed>
     */
    private function normaliseFile( \WP_Post $post ): array {
        $meta     = wp_get_attachment_metadata( $post->ID );
        $fileSize = is_array( $meta ) && isset( $meta['filesize'] )
            ? (int) $meta['filesize']
            : (int) get_post_meta( $post->ID, '_wp_attachment_filesize', true );

        return [
            'id'         => (string) $post->ID,
            'databaseId' => $post->ID,
            'title'      => $post->post_title,
            'url'        => (string) wp_get_attachment_url( $post->ID ),
            'mimeType'   => $post->post_mime_type,
            'fileSize'   => $fileSize,
            'date'       => get_the_date( 'Y-m-d', $post->ID ),
            'altText'    => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
        ];
    }

    // -------------------------------------------------------------------------
    // Permission helpers
    // -------------------------------------------------------------------------

    /**
     * Throw a GraphQL UserError if the current user lacks a capability.
     *
     * @param  string $cap  WordPress capability slug.
     * @throws \GraphQL\Error\UserError
     */
    private function requireCap( string $cap ): void {
        if ( ! current_user_can( $cap ) ) {
            throw new \GraphQL\Error\UserError( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                /* translators: %s: capability name */
                sprintf( __( 'You do not have permission to perform this action (%s).', 'media-management-platform'), $cap )  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output
            );
        }
    }
}
