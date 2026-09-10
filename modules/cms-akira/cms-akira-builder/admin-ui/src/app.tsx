import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, newIdempotencyKey, readBootstrap } from './api';
import { Canvas } from './Canvas';
import type { BlockDefinition, BlocksResponse, Boot, Composition, PostOption, PropSchema, Revision, SemanticDiff, TimelineEntry, Tree } from './types';

const DEFAULT_TREE: Tree = { version: 1, blocks: [] };

function cloneDefaults(definition: BlockDefinition): Record<string, unknown> {
  return JSON.parse(JSON.stringify(definition.defaults ?? {})) as Record<string, unknown>;
}

function inputValue(value: unknown): string {
  return typeof value === 'string' ? value : value === undefined || value === null ? '' : String(value);
}

/**
 * Seed a freshly added value from its schema. A url-typed field with no declared
 * default must NOT be seeded as '' — the governed validator rejects an empty URL
 * ("card-grid.items[].href is unsafe or invalid"), so a new row would fail
 * validation before the user can type anything.
 */
function seedValue(schema: PropSchema): unknown {
  if (schema.default !== undefined) {
    return schema.default;
  }
  return schema.type === 'url' ? '/' : '';
}

function encodeTree(tree: Tree): string {
  return JSON.stringify(tree, null, 2);
}

function parseTree(text: string): Tree {
  const parsed = JSON.parse(text) as Tree;
  const canonicalBlocks = (blocks: unknown): boolean => Array.isArray(blocks) && blocks.every((candidate) => {
    if (candidate === null || typeof candidate !== 'object') return false;
    const block = candidate as Record<string, unknown>;
    const keys = Object.keys(block);
    return typeof block.block === 'string' && block.block !== ''
      && block.props !== null && typeof block.props === 'object' && !Array.isArray(block.props)
      && keys.every((key) => key === 'block' || key === 'props' || key === 'children')
      && (block.children === undefined || canonicalBlocks(block.children));
  });
  if (parsed === null || typeof parsed !== 'object' || parsed.version !== 1 || !canonicalBlocks(parsed.blocks)) {
    throw new Error('Invalid composition tree: expected canonical {version:1, blocks:[{block, props, children?}]}.');
  }
  return parsed;
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
  const [canvasTree, setCanvasTree] = useState<Tree>(DEFAULT_TREE);
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);
  const [parseError, setParseError] = useState('');
  const [note, setNote] = useState('');
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [revisions, setRevisions] = useState<Revision[]>([]);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [timeline, setTimeline] = useState<TimelineEntry[]>([]);
  const [diff, setDiff] = useState<SemanticDiff | null>(null);
  const [historyError, setHistoryError] = useState('');
  const [preview, setPreview] = useState<{ html: string; label: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [catalogue, setCatalogue] = useState<BlockDefinition[]>([]);
  const [catalogueError, setCatalogueError] = useState('');
  const [selectedBlock, setSelectedBlock] = useState('');
  const [blockProps, setBlockProps] = useState<Record<string, unknown>>({});

  useEffect(() => {
    api<BlocksResponse>('', boot.blocks_endpoint)
      .then((body) => {
        const blocks = Array.isArray(body.blocks) ? body.blocks : [];
        if (blocks.length === 0) throw new Error('The active theme has no block definitions.');
        setCatalogue(blocks);
        setSelectedBlock(blocks[0].id);
        setBlockProps(cloneDefaults(blocks[0]));
      })
      .catch((e: unknown) => setCatalogueError(e instanceof Error ? e.message : 'Could not load the theme block catalogue.'));
  }, [boot.blocks_endpoint]);

  const selectedDefinition = catalogue.find((definition) => definition.id === selectedBlock);

  useEffect(() => {
    if (selectedIndex !== null && canvasTree.blocks[selectedIndex]) {
      const block = canvasTree.blocks[selectedIndex];
      setSelectedBlock(block.block);
      setBlockProps(block.props);
    }
  }, [canvasTree, selectedIndex]);

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
        setCanvasTree(body.data.tree);
        const firstIndex = body.data.tree.blocks.length > 0 ? 0 : null;
        setSelectedIndex(firstIndex);
        if (firstIndex !== null) {
          setSelectedBlock(body.data.tree.blocks[firstIndex].block);
          setBlockProps(body.data.tree.blocks[firstIndex].props);
        }
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
      await handleRender('preview');
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

  const openHistory = async () => {
    setHistoryOpen(true);
    setHistoryError('');
    try {
      const out = await api<{ ok: boolean; data: { timeline: TimelineEntry[] } }>(apiBase, `/compositions/${encodeURIComponent(key)}/provenance`);
      setTimeline(out.data.timeline ?? []);
    } catch (e) {
      setHistoryError(e instanceof Error ? e.message : 'History unavailable.');
    }
  };

  const viewRevision = async (revisionId: number) => {
    setBusy(true);
    setHistoryError('');
    try {
      const [rendered, compared] = await Promise.all([
        api<{ ok: boolean; data: { html: string; revision_id: number } }>(apiBase, `/compositions/${encodeURIComponent(key)}/render?source=preview&revision_id=${revisionId}`),
        api<{ ok: boolean; data: { diff: SemanticDiff } }>(apiBase, `/compositions/${encodeURIComponent(key)}/provenance?from_revision_id=${revisionId}`),
      ]);
      setPreview({ html: rendered.data.html, label: `View as of revision ${rendered.data.revision_id}` });
      setDiff(compared.data.diff);
    } catch (e) {
      setHistoryError(e instanceof Error ? e.message : 'Historical revision unavailable.');
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
        setPreview({ html: out.data.html, label: `${source === 'preview' ? 'Last saved draft' : 'Published'} · revision ${out.data.revision_id ?? '—'}` });
      }
    } catch (e) {
      setPreview(null);
      setResultMessage(source === 'preview' ? `No draft to preview: ${e instanceof Error ? e.message : ''}` : `No published render: ${e instanceof Error ? e.message : ''}`);
    } finally {
      setBusy(false);
    }
  };

  const commitTree = (tree: Tree) => {
    setCanvasTree(tree);
    setTreeText(encodeTree(tree));
    setParseError('');
  };

  const selectCanvasBlock = (index: number) => {
    const block = canvasTree.blocks[index];
    if (!block) return;
    setSelectedIndex(index);
    setSelectedBlock(block.block);
    setBlockProps(block.props);
  };

  const setProp = (name: string, value: unknown) => {
    const props = { ...blockProps, [name]: value };
    setBlockProps(props);
    if (selectedIndex !== null && canvasTree.blocks[selectedIndex]) {
      const blocks = canvasTree.blocks.map((block, index) => index === selectedIndex ? { ...block, props } : block);
      commitTree({ ...canvasTree, blocks });
    }
  };

  const addArrayRow = (name: string, schema: PropSchema) => {
    const row = Object.fromEntries(Object.entries(schema.items?.props ?? {}).map(([key, item]) => [key, seedValue(item)]));
    const rows = Array.isArray(blockProps[name]) ? blockProps[name] as Record<string, unknown>[] : [];
    setProp(name, [...rows, row]);
  };

  const updateArrayRow = (name: string, index: number, keyName: string, value: string) => {
    const rows = Array.isArray(blockProps[name]) ? blockProps[name] as Record<string, unknown>[] : [];
    setProp(name, rows.map((row, rowIndex) => rowIndex === index ? { ...row, [keyName]: value } : row));
  };

  const addBlock = () => {
    if (!selectedDefinition) return;
    const props = JSON.parse(JSON.stringify(blockProps)) as Record<string, unknown>;
    const blocks = [...canvasTree.blocks, { block: selectedDefinition.id, props, children: [] }];
    commitTree({ ...canvasTree, blocks });
    setSelectedIndex(blocks.length - 1);
  };

  const moveBlock = (index: number, direction: -1 | 1) => {
    const destination = index + direction;
    if (destination < 0 || destination >= canvasTree.blocks.length) return;
    const blocks = [...canvasTree.blocks];
    [blocks[index], blocks[destination]] = [blocks[destination], blocks[index]];
    commitTree({ ...canvasTree, blocks });
    if (selectedIndex === index) setSelectedIndex(destination);
    else if (selectedIndex === destination) setSelectedIndex(index);
  };

  const duplicateBlock = (index: number) => {
    const duplicate = JSON.parse(JSON.stringify(canvasTree.blocks[index])) as Tree['blocks'][number];
    const blocks = [...canvasTree.blocks.slice(0, index + 1), duplicate, ...canvasTree.blocks.slice(index + 1)];
    commitTree({ ...canvasTree, blocks });
    setSelectedIndex(index + 1);
    setSelectedBlock(duplicate.block);
    setBlockProps(duplicate.props);
  };

  const deleteBlock = (index: number) => {
    const blocks = canvasTree.blocks.filter((_, blockIndex) => blockIndex !== index);
    commitTree({ ...canvasTree, blocks });
    if (blocks.length === 0) {
      setSelectedIndex(null);
      setBlockProps(selectedDefinition ? cloneDefaults(selectedDefinition) : {});
      return;
    }
    const nextIndex = Math.min(index, blocks.length - 1);
    setSelectedIndex(nextIndex);
    setSelectedBlock(blocks[nextIndex].block);
    setBlockProps(blocks[nextIndex].props);
  };

  return (
    <section className="ab">
      <p className="ab-muted">
        <a href="/cms-akira-shell/compositions">← Compositions</a>
      </p>
      <div className="ab-row">
        <h2>Composition editor</h2>
        <span className={status === 'published' ? 'ab-pill ab-pill-pub' : 'ab-pill'}>{(status ?? 'draft').toUpperCase()}</span>
        <button className="ab-btn" type="button" onClick={() => void openHistory()}>History &amp; provenance</button>
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
          <h4>Structural canvas</h4>
          <p className="ab-muted">A projection of the canonical JSON tree. Select a block to edit it below.</p>
          <Canvas blocks={canvasTree.blocks} catalogue={catalogue} selectedIndex={selectedIndex} onSelect={selectCanvasBlock} onMove={moveBlock} onDuplicate={duplicateBlock} onDelete={deleteBlock} />
          <h4>{selectedIndex === null ? 'Add a theme block' : `Edit block ${selectedIndex + 1}`}</h4>
          {catalogueError !== '' ? (
            <p className="ab-error">Block catalogue unavailable: {catalogueError} Adding blocks is disabled.</p>
          ) : catalogue.length === 0 ? (
            <p className="ab-muted">Loading active theme blocks…</p>
          ) : (
            <div className="ab-block-form">
              <label>
                Block
                <select value={selectedBlock} onChange={(e) => {
                  const definition = catalogue.find((item) => item.id === e.target.value);
                  const props = definition ? cloneDefaults(definition) : {};
                  setSelectedBlock(e.target.value);
                  setBlockProps(props);
                  if (definition && selectedIndex !== null && canvasTree.blocks[selectedIndex]) {
                    const blocks = canvasTree.blocks.map((block, index) => index === selectedIndex ? { ...block, block: definition.id, props } : block);
                    commitTree({ ...canvasTree, blocks });
                  }
                }}>
                  {!selectedDefinition && selectedBlock !== '' && <option value={selectedBlock}>{selectedBlock} — unknown block</option>}
                  {catalogue.map((definition) => <option key={definition.id} value={definition.id}>{definition.label} — {definition.category}</option>)}
                </select>
              </label>
              {selectedDefinition && Object.entries(selectedDefinition.schema.props).map(([name, schema]) => schema.type === 'array' && schema.items?.type === 'object' ? (
                <fieldset key={name}>
                  <legend>{schema.label ?? name} <small>(array of objects)</small></legend>
                  {(Array.isArray(blockProps[name]) ? blockProps[name] as Record<string, unknown>[] : []).map((row, index) => (
                    <div className="ab-array-row" key={index}>
                      {Object.entries(schema.items?.props ?? {}).map(([itemName, itemSchema]) => (
                        <label key={itemName}>{itemSchema.label ?? itemName}
                          <input type={itemSchema.type === 'url' ? 'url' : 'text'} value={inputValue(row[itemName])} onChange={(e) => updateArrayRow(name, index, itemName, e.target.value)} />
                        </label>
                      ))}
                      <button className="ab-btn" type="button" onClick={() => setProp(name, (blockProps[name] as unknown[]).filter((_, rowIndex) => rowIndex !== index))}>Remove row</button>
                    </div>
                  ))}
                  <button className="ab-btn" type="button" onClick={() => addArrayRow(name, schema)}>+ Add row</button>
                </fieldset>
              ) : (
                <label key={name}>{schema.label ?? name} {schema.type !== 'string' && schema.type !== 'url' && <small>({schema.type})</small>}
                  <input type={schema.type === 'url' ? 'url' : 'text'} value={inputValue(blockProps[name])} onChange={(e) => setProp(name, e.target.value)} />
                </label>
              ))}
              <button className="ab-btn" type="button" disabled={!selectedDefinition} onClick={addBlock}>+ Add {selectedDefinition?.label ?? 'block'}</button>
            </div>
          )}
          <label>
            Tree (JSON)
            <textarea
              spellCheck={false}
              rows={18}
              value={treeText}
              onChange={(e) => {
                setTreeText(e.target.value);
                try {
                  const tree = parseTree(e.target.value);
                  setCanvasTree(tree);
                  setParseError('');
                  if (selectedIndex !== null && tree.blocks[selectedIndex]) {
                    setSelectedBlock(tree.blocks[selectedIndex].block);
                    setBlockProps(tree.blocks[selectedIndex].props);
                  } else if (tree.blocks.length > 0) {
                    setSelectedIndex(0);
                    setSelectedBlock(tree.blocks[0].block);
                    setBlockProps(tree.blocks[0].props);
                  } else {
                    setSelectedIndex(null);
                  }
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
          <p className="ab-muted">Preview shows the last saved draft, never unsaved canvas edits.</p>
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
      {historyOpen && <div className="ab-drawer-backdrop" role="presentation" onClick={() => setHistoryOpen(false)}>
        <aside className="ab-drawer" role="dialog" aria-modal="true" aria-labelledby="ab-history-title" onClick={(event) => event.stopPropagation()}>
          <div className="ab-row ab-drawer-head"><h3 id="ab-history-title">History &amp; provenance</h3><button className="ab-btn" type="button" onClick={() => setHistoryOpen(false)}>Close</button></div>
          {historyError && <p className="ab-error">{historyError}</p>}
          <ol className="ab-timeline">
            {timeline.map((entry, index) => <li key={`${entry.kind}-${entry.created_at}-${index}`}>
              <div className="ab-row"><strong>{entry.action}</strong>{entry.was_published && <span className="ab-pill ab-pill-pub">PUBLISHED</span>}</div>
              <time>{entry.created_at}</time> · {entry.actor}<br />
              <code>{entry.capability}</code>{entry.note && <> · {entry.note}</>}
              {(entry.correlation_id || entry.request_id) && <small>Correlation: {entry.correlation_id ?? entry.request_id}</small>}
              {entry.kind === 'revision' && entry.revision_id && <button className="ab-btn" type="button" disabled={busy} onClick={() => void viewRevision(entry.revision_id as number)}>View as of r{entry.revision_id}</button>}
            </li>)}
          </ol>
          {diff && <section className="ab-diff"><h4>r{diff.from_revision_id} → current draft</h4>
            {(['added', 'removed', 'reordered'] as const).map((kind) => <div key={kind}><strong>{kind}</strong>: {diff.changes[kind].length === 0 ? 'none' : diff.changes[kind].map((item) => item.identity).join(', ')}</div>)}
            <strong>Properties changed</strong>
            {diff.changes.props_changed.length === 0 ? <p>none</p> : <ul>{diff.changes.props_changed.map((change, index) => <li key={`${change.identity}-${change.prop}-${index}`}><code>{change.identity}.{change.prop}</code>: <del>{JSON.stringify(change.old)}</del> → <ins>{JSON.stringify(change.new)}</ins></li>)}</ul>}
          </section>}
        </aside>
      </div>}
    </section>
  );
}
