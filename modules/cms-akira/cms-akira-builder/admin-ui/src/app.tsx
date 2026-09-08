import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, newIdempotencyKey, readBootstrap } from './api';
import type { Boot, Composition, PostOption, Revision, Tree } from './types';

const DEFAULT_TREE: Tree = { version: 1, blocks: [] };
const ALLOWED = ['section', 'heading', 'paragraph', 'rich_text', 'image', 'button'];

function sampleBlock(type: string): unknown {
  switch (type) {
    case 'section':
      return { type: 'section', props: { layout: 'stack' }, children: [] };
    case 'heading':
      return { type: 'heading', props: { text: 'Heading', level: 2 }, children: [] };
    case 'paragraph':
      return { type: 'paragraph', props: { text: 'Paragraph text' }, children: [] };
    case 'rich_text':
      return { type: 'rich_text', props: { content: '<p>Rich text</p>' }, children: [] };
    case 'image':
      return { type: 'image', props: { src: '/assets/akira.jpg', alt: 'Alt' }, children: [] };
    case 'button':
      return { type: 'button', props: { label: 'Read more', url: '/' }, children: [] };
    default:
      return null;
  }
}

function encodeTree(tree: Tree): string {
  return JSON.stringify(tree, null, 2);
}

function parseTree(text: string): Tree {
  const parsed = JSON.parse(text) as Tree;
  if (parsed === null || typeof parsed !== 'object' || parsed.version !== 1 || !Array.isArray(parsed.blocks)) {
    throw new Error('Invalid composition tree: expected {version:1, blocks:[]}.');
  }
  return parsed as Tree;
}

export default function App() {
  const boot = useMemo<Boot>(() => readBootstrap(), []);
  return boot.mode === 'edit' && boot.entity_key ? <EditPanel boot={boot} /> : <ListPanel boot={boot} />;
}

function LinkButton({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <a className="ab-btn ab-btn-primary" href={href}>
      {children}
    </a>
  );
}

// ── List: pick an Akira post + list existing compositions ────────────────
function ListPanel({ boot }: { boot: Boot }) {
  const [compositions, setCompositions] = useState<Composition[]>([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ ok: boolean; rows: Composition[] }>(boot.apiBase, '/compositions')
      .then((body) => setCompositions(body.rows ?? []))
      .catch((e) => setError(e instanceof Error ? e.message : 'Could not load compositions'))
      .finally(() => setLoading(false));
  }, [boot.apiBase]);

  const editable = (row: Composition) => `/cms-akira-shell/compositions/${encodeURIComponent(row.entity_key)}/edit`;

  return (
    <section className="ab">
      <h2>Compositions</h2>
      {error !== '' && <p className="ab-error">Error: {error}</p>}
      <div className="ab-cols">
        <div>
          <h3>Attach a composition to a post</h3>
          {boot.posts.length === 0 ? (
            <p className="ab-muted">No posts available to attach yet. Create a post in the Posts area first.</p>
          ) : (
            <ul className="ab-list">
              {boot.posts.map((post: PostOption) => (
                <li key={post.entity_key}>
                  <LinkButton href={`/cms-akira-shell/compositions/${encodeURIComponent(post.entity_key)}/edit`}>
                    Compose “{post.title}”
                  </LinkButton>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div>
          <h3>Existing compositions</h3>
          {loading ? (
            <p className="ab-muted">Loading…</p>
          ) : compositions.length === 0 ? (
            <p className="ab-muted">None yet.</p>
          ) : (
            <ul className="ab-list">
              {compositions.map((row) => (
                <li key={row.entity_key}>
                  <a href={editable(row)}>
                    {row.title} <span className={row.status === 'published' ? 'ab-pill ab-pill-pub' : 'ab-pill'}>{(row.status ?? 'draft').toUpperCase()}</span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}

// ── Editor: JSON tree editor + validated block form + preview + publish ──
function EditPanel({ boot }: { boot: Boot }) {
  const apiBase = boot.apiBase;
  const key = boot.entity_key as string;
  const [title, setTitle] = useState('');
  const [status, setStatus] = useState<'draft' | 'published'>('draft');
  const [revision, setRevision] = useState<number | null>(null);
  const [publishedRevision, setPublishedRevision] = useState<number | null>(null);
  const [treeText, setTreeText] = useState(encodeTree(DEFAULT_TREE));
  const [parseError, setParseError] = useState('');
  const [note, setNote] = useState('');
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [revisions, setRevisions] = useState<Revision[]>([]);
  const [preview, setPreview] = useState<{ html: string; label: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const reload = useCallback(async () => {
    setResult(null);
    const body = await api<{ ok: boolean; data?: Composition }>(apiBase, `/compositions/${encodeURIComponent(key)}`).catch((e: unknown) => {
      setResult({ ok: false, message: e instanceof ApiError && e.status === 404 ? 'No composition for this post yet — create one below.' : e instanceof Error ? e.message : 'Load failed.' });
      return null;
    });
    if (body && body.data) {
      setTitle(body.data.title);
      setStatus(body.data.status);
      setRevision(body.data.current_revision_id);
      setPublishedRevision(body.data.published_revision_id);
      if (body.data.tree) {
        setTreeText(encodeTree(body.data.tree));
      }
    }
    const hist = await api<{ ok: boolean; rows?: Revision[] }>(apiBase, `/compositions/${encodeURIComponent(key)}/revisions`).catch(() => ({ ok: false, rows: [] }));
    setRevisions(hist.rows ?? []);
  }, [apiBase, key]);

  useEffect(() => {
    void reload();
  }, [reload]);

  const setResultMessage = (message: string) => setResult({ ok: false, message });

  const currentTree = (): Tree | null => {
    try {
      return parseTree(treeText);
    } catch (e) {
      setParseError(e instanceof Error ? e.message : 'Invalid JSON.');
      return null;
    }
  };

  const handleValidate = async () => {
    const tree = currentTree();
    if (!tree) {
      return;
    }
    setParseError('');
    setBusy(true);
    try {
      const out = await api<{ ok: boolean; data?: { valid?: boolean } }>(apiBase, '/validate', {
        method: 'POST',
        body: JSON.stringify({ tree, idempotency_key: newIdempotencyKey() }),
      });
      setResult({ ok: true, message: `Validated — ${(tree.blocks ?? []).length} top-level block(s).` });
      void out;
    } catch (e) {
      setResultMessage(e instanceof Error ? `Validation failed: ${e.message}` : 'Validation failed.');
    } finally {
      setBusy(false);
    }
  };

  const handleSave = async () => {
    const tree = currentTree();
    if (!tree) {
      return;
    }
    setParseError('');
    setBusy(true);
    const base = revision;
    const body = { entity_type: 'post', entity_key: key, title, tree, base_revision_id: base ?? undefined, change_note: note || 'draft edit', idempotency_key: newIdempotencyKey() };
    try {
      if (base === null) {
        const out = await api<{ ok: boolean; data?: { current_revision_id?: number } }>(apiBase, '/compositions', { method: 'POST', body: JSON.stringify(body) });
        setResult({ ok: true, message: 'Created draft composition.' });
        setRevision(out.data?.current_revision_id ?? null);
      } else {
        const out = await api<{ ok: boolean; data?: { current_revision_id?: number } }>(apiBase, `/compositions/${encodeURIComponent(key)}`, { method: 'POST', body: JSON.stringify(body) });
        setResult({ ok: true, message: 'Saved draft revision.' });
        setRevision(out.data?.current_revision_id ?? null);
      }
      await reload();
    } catch (e) {
      setResultMessage(e instanceof ApiError && e.status === 409 ? 'Revision conflict — refresh and retry.' : e instanceof Error ? e.message : 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const handlePublish = async () => {
    if (!window.confirm('Publish the current preview to all visitors?')) {
      return;
    }
    setBusy(true);
    try {
      await api(apiBase, `/compositions/${encodeURIComponent(key)}/publish`, { method: 'POST', body: JSON.stringify({ idempotency_key: newIdempotencyKey() }) });
      setResult({ ok: true, message: 'Published.' });
      await reload();
    } catch (e) {
      setResultMessage(e instanceof Error ? e.message : 'Publish failed.');
    } finally {
      setBusy(false);
    }
  };

  const handleUnpublish = async () => {
    if (!window.confirm('Unpublish this composition?')) {
      return;
    }
    setBusy(true);
    try {
      await api(apiBase, `/compositions/${encodeURIComponent(key)}/unpublish`, { method: 'POST', body: JSON.stringify({ idempotency_key: newIdempotencyKey() }) });
      setResult({ ok: true, message: 'Unpublished.' });
      await reload();
    } catch (e) {
      setResultMessage(e instanceof Error ? e.message : 'Unpublish failed.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm('Delete this composition and all its revisions?')) {
      return;
    }
    setBusy(true);
    try {
      await api(apiBase, `/compositions/${encodeURIComponent(key)}/delete`, { method: 'POST', body: JSON.stringify({ idempotency_key: newIdempotencyKey() }) });
      setResult({ ok: true, message: 'Deleted.' });
      window.location.href = '/cms-akira-shell/compositions';
    } catch (e) {
      setResultMessage(e instanceof Error ? e.message : 'Delete failed.');
    } finally {
      setBusy(false);
    }
  };

  const handleRender = async (source: 'preview' | 'published') => {
    setBusy(true);
    try {
      const out = await api<{ ok: boolean; data?: { html: string; source: 'preview' | 'published'; revision_id: number | null } }>(
        apiBase,
        `/compositions/${encodeURIComponent(key)}/render?source=${source}`,
      );
      if (out.data) {
        setPreview({ html: out.data.html, label: `${source === 'preview' ? 'Preview (draft)' : 'Published'} · revision ${out.data.revision_id ?? '—'}` });
      }
    } catch (e) {
      setPreview(null);
      setResultMessage(source === 'preview' ? `No draft to preview: ${e instanceof Error ? e.message : ''}` : `No published render: ${e instanceof Error ? e.message : ''}`);
    } finally {
      setBusy(false);
    }
  };

  const addBlock = (type: string) => {
    const tree = currentTree();
    if (!tree) {
      return;
    }
    const block = sampleBlock(type);
    if (block === null) {
      return;
    }
    tree.blocks = [...(tree.blocks ?? []), block as Tree['blocks'][number]];
    setTreeText(encodeTree(tree));
    setParseError('');
  };

  return (
    <section className="ab">
      <p className="ab-muted">
        <a href="/cms-akira-shell/compositions">← Compositions</a>
      </p>
      <div className="ab-row">
        <h2>Composition editor</h2>
        <span className={status === 'published' ? 'ab-pill ab-pill-pub' : 'ab-pill'}>{(status ?? 'draft').toUpperCase()}</span>
      </div>
      <p className="ab-muted">
        Attached to post <code>{key}</code> · current revision {revision ?? 'none'} · published revision {publishedRevision ?? 'none'}
      </p>
      {result && <p className={result.ok ? 'ab-ok' : 'ab-error'}>{result.message}</p>}

      <div className="ab-cols">
        <div>
          <h3>Composition</h3>
          <label>
            Title
            <input value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <h4>Add a validated block (10A allowlist)</h4>
          <div className="ab-btnrow">
            {ALLOWED.map((type) => (
              <button key={type} className="ab-btn" type="button" onClick={() => addBlock(type)}>
                + {type}
              </button>
            ))}
          </div>
          <label>
            Tree (JSON)
            <textarea
              spellCheck={false}
              rows={18}
              value={treeText}
              onChange={(e) => {
                setTreeText(e.target.value);
                try {
                  parseTree(e.target.value);
                  setParseError('');
                } catch (err) {
                  setParseError(err instanceof Error ? err.message : 'Invalid JSON.');
                }
              }}
            />
          </label>
          {parseError !== '' && <p className="ab-error">JSON: {parseError}</p>}
          <label>
            Change note
            <input value={note} placeholder="what changed?" onChange={(e) => setNote(e.target.value)} />
          </label>
          <div className="ab-btnrow">
            <button className="ab-btn ab-btn-primary" type="button" disabled={busy} onClick={() => void handleValidate()}>
              Validate
            </button>
            <button className="ab-btn ab-btn-primary" type="button" disabled={busy} onClick={() => void handleSave()}>
              {revision === null ? 'Create & save draft' : 'Save draft'}
            </button>
          </div>
        </div>

        <div>
          <h3>Revisions</h3>
          {revisions.length === 0 ? (
            <p className="ab-muted">No revisions yet.</p>
          ) : (
            <ul className="ab-list">
              {revisions.map((rev) => (
                <li key={rev.revision_id}>
                  r{rev.revision_id} — {rev.change_note || '(no note)'} <span className="ab-muted">base {rev.base_revision_id ?? '—'}</span>
                </li>
              ))}
            </ul>
          )}

          <h3>Preview (server-rendered)</h3>
          <div className="ab-btnrow">
            <button className="ab-btn" type="button" disabled={busy} onClick={() => void handleRender('preview')}>
              Render preview (draft)
            </button>
            <button className="ab-btn" type="button" disabled={busy} onClick={() => void handleRender('published')}>
              Render published
            </button>
          </div>
          {preview && (
            <div className="ab-preview">
              <p className="ab-muted">{preview.label}</p>
              <iframe title="composition-preview" sandbox="" srcDoc={preview.html} />
            </div>
          )}

          <h3>Lifecycle</h3>
          <div className="ab-btnrow">
            <button className="ab-btn ab-btn-primary" type="button" disabled={busy || status === 'published'} onClick={() => void handlePublish()}>
              Publish
            </button>
            <button className="ab-btn" type="button" disabled={busy || status !== 'published'} onClick={() => void handleUnpublish()}>
              Unpublish
            </button>
            <button className="ab-btn ab-btn-danger" type="button" disabled={busy} onClick={() => void handleDelete()}>
              Delete
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
