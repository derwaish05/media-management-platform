// ---------------------------------------------------------------------------
// Folder node shape — matches FolderRepository::getTree() output
// ---------------------------------------------------------------------------
export interface MmpFolder {
  id: number
  name: string
  slug: string
  parent: number
  color: string       // hex color e.g. '#3b82f6'
  count: number       // direct file count (not including children)
  children: MmpFolder[]
}

// ---------------------------------------------------------------------------
// User preferences — stored in wp_mmp_user_prefs
// ---------------------------------------------------------------------------
export interface MmpUserPrefs {
  folder_id: number | null  // last active folder (null = All Files)
  sort_files: 'name' | 'date' | 'modified' | 'author' | 'size'
  sort_dir: 'asc' | 'desc'
  sidebar_w: number         // sidebar width in pixels
  ui_theme: 'default' | 'win11' | 'dropbox'
}

// ---------------------------------------------------------------------------
// REST API response wrappers
// ---------------------------------------------------------------------------
export interface MmpApiResponse<T> {
  success: boolean
  data: T
  message?: string
}

// ---------------------------------------------------------------------------
// WP localized data — passed via wp_localize_script as window.MmpConfig
// ---------------------------------------------------------------------------
export interface MmpConfig {
  restUrl: string         // e.g. 'https://site.com/wp-json/mmp/v1'
  nonce: string           // WP REST nonce (X-WP-Nonce header)
  userId: number
  isAdmin: boolean        // true when current user has manage_options cap
  folderMode: 'global' | 'per_user'
  postType?: string       // set on CPT list screens (edit.php); absent on upload.php
  initialTree: MmpFolder[]
  userPrefs: MmpUserPrefs
  licenceTier: 'free' | 'pro' | 'business' | 'agency'
}

// ---------------------------------------------------------------------------
// Permissions — per-folder role/user access rules
// ---------------------------------------------------------------------------

export type MmpPermissionEntity = 'role' | 'user'

export type MmpPresetName = 'designer' | 'editor' | 'publisher' | 'viewer' | 'none'

export interface MmpPermissionRule {
  id: number
  folder_id: number
  entity: MmpPermissionEntity
  entity_id: string        // role slug or user ID (string)
  can_read: boolean
  can_write: boolean
  can_delete: boolean
  display_name?: string    // decorated by REST endpoint
}

export interface MmpPresetDefinition {
  can_read: boolean
  can_write: boolean
  can_delete: boolean
}

// ---------------------------------------------------------------------------
// Smart Tags / Labels
// ---------------------------------------------------------------------------

export interface MmpTag {
  id: number
  name: string
  slug: string
  color: string         // hex e.g. '#3b82f6'
  usage_count?: number  // decorated by REST endpoint (GET /tags only)
}

export type MmpSmartConditionType = 'tag' | 'mime' | 'date_after' | 'date_before'

export interface MmpSmartCondition {
  type: MmpSmartConditionType
  tag_id?: number   // present when type === 'tag'
  mime?: string     // present when type === 'mime'
  date?: string     // present when type === 'date_after' | 'date_before'
}

export interface MmpSmartRules {
  mode: 'AND' | 'OR'
  conditions: MmpSmartCondition[]
}

// ---------------------------------------------------------------------------
// Utility / union types
// ---------------------------------------------------------------------------

/** Fields by which media items can be sorted */
export type MmpSortField = 'name' | 'date' | 'modified' | 'author' | 'size'

/** Sort direction */
export type MmpSortDir = 'asc' | 'desc'

/** Bulk actions available on selected media items */
export type MmpBulkAction = 'move' | 'delete' | 'download'

// ---------------------------------------------------------------------------
// Global augmentation — WordPress localizes data onto window.MmpConfig
// ---------------------------------------------------------------------------
declare global {
  interface Window {
    MmpConfig: MmpConfig
  }
}
