// Canonical Akira composition tree and builder projections (mirrors the 10A
// allowlisted schema exposed by akira.builder.*@1). Render the JSON via the
// server-side validate/render endpoints only — never trust client markup.

export interface Block {
  block: string;
  props: Record<string, unknown>;
  children?: Block[];
}

export interface Tree {
  version: 1;
  blocks: Block[];
}

export interface Composition {
  entity_type: string;
  entity_key: string;
  title: string;
  status: 'draft' | 'published';
  current_revision_id: number | null;
  published_revision_id: number | null;
  version: number;
  created_at: string;
  updated_at: string;
  tree?: Tree;
}

export interface Revision {
  revision_id: number;
  base_revision_id: number | null;
  author_id: number;
  change_note: string;
  created_at: string;
}

export interface PostOption {
  entity_type: 'post';
  entity_key: string;
  title: string;
}

export interface Boot {
  mode: 'list' | 'edit';
  apiBase: string;
  blocks_endpoint: string;
  entity_key?: string;
  posts: PostOption[];
}

export interface PropSchema {
  type: string;
  label?: string;
  default?: unknown;
  items?: { type: string; props?: Record<string, PropSchema> };
}

export interface BlockDefinition {
  id: string;
  label: string;
  category: string;
  schema: { props: Record<string, PropSchema> };
  defaults: Record<string, unknown>;
}

export interface BlocksResponse {
  ok: boolean;
  theme_slug: string;
  blocks: BlockDefinition[];
}

export interface ListResponse {
  ok: boolean;
  rows: Composition[];
  total: number;
}

export interface GetResponse {
  ok: boolean;
  data?: Composition;
  error?: string;
}

export interface RenderResponse {
  ok: boolean;
  data?: {
    html: string;
    source: 'preview' | 'published';
    revision_id: number | null;
    theme_slug: string;
    view_id: string;
  };
  error?: string;
}

export interface MutationResponse {
  ok: boolean;
  operation?: string;
  data?: Record<string, unknown> & { current_revision_id?: number; published_revision_id?: number | null; status?: string; deleted?: boolean; valid?: boolean };
  error?: string;
}
