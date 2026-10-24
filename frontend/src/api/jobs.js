/**
 * Customer jobsite notebook: same-origin, authenticated DTB account API.
 * Backend enforces ownership. No client-side persistence of customer notes.
 */
import { apiClient } from './client.js';

const base = '/wp-json/dtb/v1/account/jobs';

function query(params) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) search.set(key, String(value));
  });
  return search.toString();
}

function mutation(path, method, payload) {
  return apiClient(path, {
    method,
    ...(payload !== undefined ? { body: JSON.stringify(payload) } : {}),
  });
}

export const listJobs = ({ page = 1, perPage = 20, archived = false, search = '' } = {}) =>
  apiClient(`${base}?${query({ page, per_page: perPage, archived: archived ? 'true' : 'false', search })}`);
export const createJob = (data) => mutation(base, 'POST', data);
export const updateJob = (id, data) => mutation(`${base}/${encodeURIComponent(id)}`, 'PATCH', data);
export const deleteJob = (id) => mutation(`${base}/${encodeURIComponent(id)}`, 'DELETE');
export const listJobNotes = (jobId, page = 1) =>
  apiClient(`${base}/${encodeURIComponent(jobId)}/notes?page=${encodeURIComponent(page)}`);
export const createJobNote = (jobId, content) =>
  mutation(`${base}/${encodeURIComponent(jobId)}/notes`, 'POST', { content });
export const updateJobNote = (jobId, noteId, content) =>
  mutation(`${base}/${encodeURIComponent(jobId)}/notes/${encodeURIComponent(noteId)}`, 'PATCH', { content });
export const deleteJobNote = (jobId, noteId) =>
  mutation(`${base}/${encodeURIComponent(jobId)}/notes/${encodeURIComponent(noteId)}`, 'DELETE');
