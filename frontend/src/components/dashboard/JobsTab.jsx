/**
 * My Jobs: intentionally small, customer-owned jobsite notebook.
 * No workflow, crew management, or shadow commerce state.
 */
import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, ArrowRight, Archive, ArchiveRestore, Check, ChevronRight, ExternalLink, MapPin, NotebookPen, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { createJob, updateJob, deleteJob, listJobs, listJobNotes, createJobNote, updateJobNote, deleteJobNote } from '../../api/jobs.js';
import '../../styles/dashboard-experience.css';

const PAGE_SIZE = 20;
const emptyForm = { name: '', location: '' };
const dateLabel = (value) => {
  if (!value) return '';
  const time = new Date(value);
  return Number.isNaN(time.getTime()) ? '' : time.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

function JobEditor({ initial = emptyForm, heading, onCancel, onSave, busy }) {
  const [form, setForm] = useState({ name: initial.name || '', location: initial.location || '' });
  const [error, setError] = useState('');
  const submit = async (event) => {
    event.preventDefault();
    if (!form.name.trim()) { setError('Enter a job name.'); return; }
    setError('');
    try { await onSave({ name: form.name.trim(), location: form.location.trim() }); }
    catch (err) { setError(err?.message || 'Could not save this job.'); }
  };
  return (
    <form className="dtb-jobs-editor" onSubmit={submit}>
      <div className="dtb-jobs-editor__head"><h3>{heading}</h3><button type="button" className="dtb-jobs-icon-btn" onClick={onCancel} aria-label="Cancel editing"><X size={18}/></button></div>
      <label>Job name <span className="dtb-required">*</span>
        <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} maxLength={120} required placeholder="e.g. Oak Ridge Apartments" disabled={busy} autoFocus />
      </label>
      <label>Job location <span className="dtb-jobs-optional">(optional)</span>
        <input value={form.location} onChange={(event) => setForm((current) => ({ ...current, location: event.target.value }))} maxLength={250} placeholder="Address, city, or jobsite name" disabled={busy} />
      </label>
      {error && <p role="alert" className="dtb-jobs-error">{error}</p>}
      <div className="dtb-jobs-form-actions"><button type="button" className="dtb-jobs-btn dtb-jobs-btn--subtle" onClick={onCancel}>Cancel</button><button type="submit" className="dtb-jobs-btn dtb-jobs-btn--primary" disabled={busy}>{busy ? 'Saving…' : 'Save Job'}</button></div>
    </form>
  );
}

function JobNotes({ jobId }) {
  const [notes, setNotes] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [content, setContent] = useState('');
  const [editingId, setEditingId] = useState(null);
  const [editingText, setEditingText] = useState('');

  const load = useCallback(async (nextPage = 1, append = false) => {
    const data = await listJobNotes(jobId, nextPage);
    setNotes((previous) => append ? [...previous, ...(data.notes || []).filter((note) => !previous.some((old) => old.id === note.id))] : data.notes || []);
    setTotal(Number(data.total) || 0);
    setPage(nextPage);
  }, [jobId]);

  useEffect(() => {
    let live = true;
    // The editor is keyed by job ID, so initial state already represents loading.
    listJobNotes(jobId, 1).then((data) => {
      if (!live) return;
      setNotes(data.notes || []);
      setTotal(Number(data.total) || 0);
    }).catch((err) => { if (live) setError(err?.message || 'Notes could not be loaded.'); })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [jobId]);

  const saveNote = async (event) => {
    event.preventDefault();
    if (!content.trim() || busy) return;
    setBusy(true); setError('');
    try {
      await createJobNote(jobId, content.trim());
      setContent('');
      await load(1);
    } catch (err) { setError(err?.message || 'Unable to add note.'); }
    finally { setBusy(false); }
  };

  const commitEdit = async (noteId) => {
    if (!editingText.trim() || busy) return;
    setBusy(true); setError('');
    try {
      const response = await updateJobNote(jobId, noteId, editingText.trim());
      setNotes((previous) => previous.map((note) => note.id === noteId ? response.note : note));
      setEditingId(null);
    } catch (err) { setError(err?.message || 'Unable to update note.'); }
    finally { setBusy(false); }
  };

  const remove = async (noteId) => {
    if (!window.confirm('Permanently delete this note?') || busy) return;
    setBusy(true); setError('');
    try {
      await deleteJobNote(jobId, noteId);
      setNotes((previous) => previous.filter((note) => note.id !== noteId));
      setTotal((previous) => Math.max(0, previous - 1));
    } catch (err) { setError(err?.message || 'Unable to delete note.'); }
    finally { setBusy(false); }
  };

  const loadMore = async () => {
    setLoading(true); setError('');
    try { await load(page + 1, true); }
    catch (err) { setError(err?.message || 'Could not load additional notes.'); }
    finally { setLoading(false); }
  };

  return (
    <section className="dtb-jobs-notes" aria-label="Job notes">
      <div className="dtb-jobs-section-head"><div><h3>Job Notes</h3><p>Measurements, reminders, material needs—anything you want to remember.</p></div><span className="dtb-jobs-count">{total} notes</span></div>
      <form className="dtb-jobs-compose" onSubmit={saveNote}>
        <label htmlFor="dtb-new-note">Add a note</label>
        <textarea id="dtb-new-note" placeholder="Write a note about this job…" value={content} maxLength={5000} rows={3} disabled={busy} onChange={(e) => setContent(e.target.value)} />
        <div className="dtb-jobs-compose-foot"><span>{content.length}/5000</span><button className="dtb-jobs-btn dtb-jobs-btn--primary" type="submit" disabled={busy || !content.trim()}><Plus size={16}/> {busy ? 'Saving…' : 'Save Note'}</button></div>
      </form>
      {error && <p className="dtb-jobs-error" role="alert">{error} <button type="button" onClick={() => { setError(''); setLoading(true); load(1).catch((err) => setError(err?.message || 'Unable to reload notes.')).finally(() => setLoading(false)); }}>Retry</button></p>}
      {loading && notes.length === 0 ? <p className="dtb-jobs-muted" role="status">Loading notes…</p> : null}
      {!loading && notes.length === 0 && !error ? <div className="dtb-jobs-empty dtb-jobs-empty--small"><NotebookPen size={22}/><p>No notes yet. Add anything you need to remember about this job.</p></div> : null}
      <div className="dtb-jobs-note-list">
        {notes.map((note) => (
          <article key={note.id} className="dtb-jobs-note">
            <div className="dtb-jobs-note__top"><time dateTime={note.created_at}>{dateLabel(note.created_at)}</time><div className="dtb-jobs-note__controls"><button type="button" title="Edit note" aria-label="Edit note" disabled={busy} onClick={() => { setEditingId(note.id); setEditingText(note.content); }}><Pencil size={15}/></button><button type="button" title="Delete note" aria-label="Delete note" disabled={busy} onClick={() => remove(note.id)}><Trash2 size={15}/></button></div></div>
            {editingId === note.id ? <div className="dtb-jobs-note__edit"><textarea aria-label="Edit note text" value={editingText} maxLength={5000} rows={3} onChange={(e) => setEditingText(e.target.value)} disabled={busy}/><div className="dtb-jobs-form-actions"><button className="dtb-jobs-btn dtb-jobs-btn--subtle" type="button" onClick={() => setEditingId(null)}>Cancel</button><button className="dtb-jobs-btn dtb-jobs-btn--primary" disabled={busy || !editingText.trim()} type="button" onClick={() => commitEdit(note.id)}><Check size={15}/> Save</button></div></div> : <p className="dtb-jobs-note__text">{note.content}</p>}
          </article>
        ))}
      </div>
      {notes.length < total && <button type="button" className="dtb-jobs-btn dtb-jobs-btn--subtle dtb-jobs-load" onClick={loadMore} disabled={loading}>{loading ? 'Loading…' : 'Load more notes'}</button>}
    </section>
  );
}

export default function JobsTab({ onJobsChanged }) {
  const [archived, setArchived] = useState(false);
  const [jobs, setJobs] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [selected, setSelected] = useState(null);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const handle = window.setTimeout(() => { setPage(1); setSearchTerm(query.trim()); }, 240);
    return () => window.clearTimeout(handle);
  }, [query]);

  useEffect(() => {
    let live = true;
    Promise.resolve().then(() => {
      if (!live) return;
      setLoading(true); setError('');
      return listJobs({ page, perPage: PAGE_SIZE, archived, search: searchTerm }).then((response) => {
        if (!live) return;
        setJobs(response.jobs || []); setTotal(Number(response.total) || 0);
      }).catch((err) => {
        if (live) { setJobs([]); setTotal(0); setError(err?.message || 'Could not load your jobs.'); }
      }).finally(() => { if (live) setLoading(false); });
    });
    return () => { live = false; };
  }, [page, archived, searchTerm, revision]);

  const refresh = () => { setRevision((value) => value + 1); onJobsChanged?.(); };
  const saveNew = async (data) => {
    if (busy) return;
    setBusy(true);
    try {
      const response = await createJob(data);
      setShowCreate(false); setArchived(false); setPage(1); setSelected(response.job); refresh();
    } finally { setBusy(false); }
  };
  const saveExisting = async (data) => {
    if (!selected || busy) return;
    setBusy(true);
    try { const response = await updateJob(selected.id, data); setSelected(response.job); setEditing(false); refresh(); }
    finally { setBusy(false); }
  };
  const toggleArchive = async () => {
    if (!selected || busy) return;
    setBusy(true); setError('');
    try { await updateJob(selected.id, { archived: !selected.archived }); setSelected(null); setArchived(false); setPage(1); refresh(); }
    catch (err) { setError(err?.message || 'Could not change archive status.'); }
    finally { setBusy(false); }
  };
  const removeJob = async () => {
    if (!selected || busy || !window.confirm('Permanently delete this job and all its notes? This cannot be undone.')) return;
    setBusy(true); setError('');
    try { await deleteJob(selected.id); setSelected(null); setPage(1); refresh(); }
    catch (err) { setError(err?.message || 'Could not delete this job.'); }
    finally { setBusy(false); }
  };


  return (
    <section className="dtb-jobs">
      <header className="dtb-jobs-header"><div><span className="dtb-jobs-eyebrow">YOUR WORKSPACE</span><h2>My Jobs</h2><p>Keep jobsite locations and notes in one easy place.</p></div>{!selected && <button type="button" className="dtb-jobs-btn dtb-jobs-btn--primary" onClick={() => setShowCreate(true)}><Plus size={17}/> New Job</button>}</header>
      {error && <div role="alert" className="dtb-jobs-error">{error} <button type="button" onClick={refresh}>Retry</button></div>}
      {selected ? (
        <div className="dtb-jobs-detail">
          <button type="button" className="dtb-jobs-back" onClick={() => { setSelected(null); setEditing(false); refresh(); }}><ArrowLeft size={17}/> All Jobs</button>
          {editing ? <JobEditor key={selected.id} initial={selected} heading="Edit Job" busy={busy} onCancel={() => setEditing(false)} onSave={saveExisting}/> : (
            <div className="dtb-jobs-detail__heading"><div><h3>{selected.name}</h3>{selected.location ? <a className="dtb-jobs-location" target="_blank" rel="noopener noreferrer" href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(selected.location)}`}><MapPin size={15}/>{selected.location}<ExternalLink size={13}/></a> : <p className="dtb-jobs-muted">No location added</p>}</div><div className="dtb-jobs-detail__actions"><button className="dtb-jobs-btn dtb-jobs-btn--subtle" onClick={() => setEditing(true)} type="button"><Pencil size={15}/> Edit Job</button><button className="dtb-jobs-btn dtb-jobs-btn--subtle" onClick={toggleArchive} disabled={busy} type="button">{selected.archived ? <ArchiveRestore size={15}/> : <Archive size={15}/>} {selected.archived ? 'Restore' : 'Archive'}</button><button className="dtb-jobs-btn dtb-jobs-btn--danger" onClick={removeJob} disabled={busy} type="button"><Trash2 size={15}/> Delete</button></div></div>
          )}
          <JobNotes key={selected.id} jobId={selected.id}/>
        </div>
      ) : (
        <>
          {showCreate && <JobEditor heading="Create New Job" onCancel={() => setShowCreate(false)} onSave={saveNew} busy={busy}/>}
          <div className="dtb-jobs-toolbar"><div className="dtb-jobs-search"><Search size={17}/><input aria-label="Search jobs" placeholder="Search jobs or locations…" value={query} onChange={(event) => setQuery(event.target.value)}/></div><div className="dtb-jobs-segment" aria-label="Job organization"><button aria-pressed={!archived} className={!archived ? 'is-active' : ''} type="button" onClick={() => { setArchived(false); setPage(1); setQuery(''); }}>Active</button><button aria-pressed={archived} className={archived ? 'is-active' : ''} type="button" onClick={() => { setArchived(true); setPage(1); setQuery(''); }}>Archived</button></div></div>
          <p className="dtb-jobs-meta">{loading ? 'Loading jobs…' : `${total} ${archived ? 'archived' : 'active'} ${total === 1 ? 'job' : 'jobs'}`}</p>
          {loading ? <div className="dtb-jobs-loading" role="status">Loading your jobs…</div> : jobs.length ? (
            <div className="dtb-jobs-grid">{jobs.map((job) => <button type="button" key={job.id} className="dtb-jobs-card" onClick={() => { setSelected(job); setEditing(false); }}><span className="dtb-jobs-card__icon"><MapPin size={19}/></span><span className="dtb-jobs-card__body"><strong>{job.name}</strong><span className="dtb-jobs-card__location">{job.location || 'No location added'}</span><small>Updated {dateLabel(job.updated_at)}</small></span><ChevronRight size={18} className="dtb-jobs-card__arrow"/></button>)}</div>
          ) : !error && <div className="dtb-jobs-empty"><NotebookPen size={28}/><h3>{query ? 'No matching jobs' : archived ? 'No archived jobs' : 'Organize your jobsites'}</h3><p>{query ? 'Try a different search.' : archived ? 'Archived jobs will appear here.' : 'Save a job name, location, and any notes you want to remember.'}</p>{!archived && !query && !showCreate && <button type="button" className="dtb-jobs-btn dtb-jobs-btn--primary" onClick={() => setShowCreate(true)}><Plus size={16}/> Create First Job</button>}</div>}
          {total > PAGE_SIZE && <div className="dtb-jobs-pages"><button type="button" className="dtb-jobs-btn dtb-jobs-btn--subtle" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {page} of {Math.ceil(total / PAGE_SIZE)}</span><button type="button" className="dtb-jobs-btn dtb-jobs-btn--subtle" disabled={page * PAGE_SIZE >= total || loading} onClick={() => setPage((value) => value + 1)}>Next <ArrowRight size={15}/></button></div>}
        </>
      )}
    </section>
  );
}
