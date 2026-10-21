/**
 * frontend/src/components/repairs/RepairUpdateComposer.jsx
 *
 * Unified customer update composer:
 * - short note/comment
 * - optional photos
 * - anchored attachment dropdown (photo library / camera)
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { m as motion, AnimatePresence } from 'framer-motion';
import { Camera, ImagePlus, MessageSquare, Send, X } from 'lucide-react';
import { submitRepairComment, uploadRepairMedia } from '../../api/repairs.js';
import { dtbTransition } from '../../motion/dtbMotion.js';

const MAX_CHARS = 600;
const MAX_FILES = 5;
const MAX_SIZE_MB = 5;
const MAX_BYTES = MAX_SIZE_MB * 1024 * 1024;
const ACCEPTED_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

export default function RepairUpdateComposer( { repairId, token, onSubmitted } ) {
  const [ comment, setComment ] = useState( '' );
  const [ files, setFiles ] = useState( [] ); // { file, preview }
  const [ menuOpen, setMenuOpen ] = useState( false );
  const [ sending, setSending ] = useState( false );
  const [ error, setError ] = useState( null );
  const [ success, setSuccess ] = useState( null );

  const menuRef = useRef( null );
  const attachButtonRef = useRef( null );
  const libraryInputRef = useRef( null );
  const cameraInputRef = useRef( null );

  const remaining = useMemo( () => MAX_CHARS - comment.length, [ comment.length ] );
  const hasPayload = comment.trim().length > 0 || files.length > 0;
  const canSend = hasPayload && ! sending && comment.length <= MAX_CHARS;

  useEffect( () => {
    if ( ! menuOpen ) return undefined;
    const onPointerDown = ( e ) => {
      const clickInsideMenu = menuRef.current?.contains( e.target );
      const clickToggle = attachButtonRef.current?.contains( e.target );
      if ( ! clickInsideMenu && ! clickToggle ) setMenuOpen( false );
    };
    document.addEventListener( 'pointerdown', onPointerDown );
    return () => document.removeEventListener( 'pointerdown', onPointerDown );
  }, [ menuOpen ] );

  const appendFiles = ( incoming ) => {
    const errs = [];

    setFiles( ( prev ) => {
      const next = [ ...prev ];

      for ( const f of incoming ) {
        if ( next.length >= MAX_FILES ) {
          errs.push( `Maximum ${ MAX_FILES } photos allowed.` );
          break;
        }
        if ( ! ACCEPTED_TYPES.includes( f.type ) ) {
          errs.push( `"${ f.name }" is not a supported image type.` );
          continue;
        }
        if ( f.size > MAX_BYTES ) {
          errs.push( `"${ f.name }" exceeds ${ MAX_SIZE_MB } MB.` );
          continue;
        }
        next.push( { file: f, preview: URL.createObjectURL( f ) } );
      }

      return next;
    } );

    if ( errs.length > 0 ) setError( errs[0] );
  };

  const onSelectFiles = ( e ) => {
    if ( e.target.files?.length ) {
      appendFiles( Array.from( e.target.files ) );
      e.target.value = '';
    }
  };

  const removeFile = ( idx ) => {
    setFiles( ( prev ) => {
      const target = prev[ idx ];
      if ( target?.preview ) URL.revokeObjectURL( target.preview );
      return prev.filter( ( _, i ) => i !== idx );
    } );
  };

  const handleSend = async ( e ) => {
    e.preventDefault();
    if ( ! canSend ) return;

    setSending( true );
    setError( null );
    setSuccess( null );

    try {
      if ( files.length > 0 ) {
        const formData = new FormData();
        files.forEach( ( { file } ) => formData.append( 'files[]', file ) );
        await uploadRepairMedia( repairId, formData, token );
      }

      if ( comment.trim().length > 0 ) {
        await submitRepairComment( repairId, token, comment.trim() );
      }

      files.forEach( ( { preview } ) => URL.revokeObjectURL( preview ) );
      setFiles( [] );
      setComment( '' );
      setSuccess( 'Your update was sent to our repair team.' );
      if ( onSubmitted ) onSubmitted();
    } catch ( err ) {
      setError( err?.message || 'Could not send update. Please try again.' );
    } finally {
      setSending( false );
    }
  };

  return (
    <div className="bg-white rounded-2xl border border-neutral-200 shadow-sm p-5">
      <div className="flex items-center gap-2 mb-2">
        <MessageSquare size={ 16 } className="text-blue-600" />
        <h3 className="text-sm font-semibold text-neutral-800">Send an Update</h3>
      </div>
      <p className="text-xs text-neutral-500 mb-3">
        Share details and optional photos with the repair team. This is not live chat.
      </p>

      <form onSubmit={ handleSend } className="space-y-3">
        <textarea
          value={ comment }
          onChange={ ( e ) => setComment( e.target.value ) }
          maxLength={ MAX_CHARS }
          rows={ 4 }
          placeholder="Add details for our technicians..."
          className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-y"
        />

        { files.length > 0 && (
          <div className="grid grid-cols-4 gap-2">
            { files.map( ( { file, preview }, idx ) => (
              <div key={ `${ file.name }-${ idx }` } className="relative aspect-square rounded-lg overflow-hidden border border-neutral-200 bg-neutral-100">
                <img src={ preview } alt={ file.name } className="w-full h-full object-cover" />
                <button
                  type="button"
                  onClick={ () => removeFile( idx ) }
                  className="absolute top-1 right-1 h-5 w-5 rounded-full bg-black/65 text-white flex items-center justify-center"
                  aria-label={ `Remove ${ file.name }` }
                >
                  <X size={ 12 } />
                </button>
              </div>
            ) ) }
          </div>
        ) }

        <div className="flex items-center justify-between gap-2">
          <span className={ `text-xs ${ remaining < 80 ? 'text-amber-600' : 'text-neutral-400' }` }>
            { remaining } characters remaining
          </span>
          <div className="relative flex items-center gap-2 overflow-visible">
            <div className="relative overflow-visible">
            <button
              ref={ attachButtonRef }
              type="button"
              onClick={ () => setMenuOpen( ( prev ) => ! prev ) }
              className="h-10 w-10 rounded-xl border border-neutral-300 text-neutral-600 hover:text-blue-600 hover:border-blue-300 bg-white transition-colors flex items-center justify-center"
              aria-label="Attach photo"
              aria-expanded={ menuOpen }
              aria-haspopup="menu"
            >
              <ImagePlus size={ 18 } />
            </button>
            <AnimatePresence>
              { menuOpen && (
                <motion.div
                  ref={ menuRef }
                  initial={{ opacity: 0, y: -6, scale: 0.98 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -4, scale: 0.98 }}
                  transition={dtbTransition.fast}
                  className="absolute right-0 top-full mt-2 z-40 w-52 origin-top-right rounded-xl border border-neutral-200 bg-white shadow-xl overflow-hidden"
                  role="menu"
                  aria-label="Photo options"
                >
                  <button
                    type="button"
                    onClick={ () => {
                      setMenuOpen( false );
                      libraryInputRef.current?.click();
                    } }
                    className="w-full px-3.5 py-2.5 text-left text-sm font-medium text-neutral-700 hover:bg-neutral-50 flex items-center gap-2"
                    role="menuitem"
                  >
                    <ImagePlus size={ 16 } className="text-blue-600" />
                    Photo Library
                  </button>
                  <button
                    type="button"
                    onClick={ () => {
                      setMenuOpen( false );
                      cameraInputRef.current?.click();
                    } }
                    className="w-full px-3.5 py-2.5 text-left text-sm font-medium text-neutral-700 hover:bg-neutral-50 border-t border-neutral-100 flex items-center gap-2"
                    role="menuitem"
                  >
                    <Camera size={ 16 } className="text-blue-600" />
                    Take Picture
                  </button>
                </motion.div>
              ) }
            </AnimatePresence>
            </div>
            <button
              type="submit"
              disabled={ ! canSend }
              className="h-10 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:bg-blue-300 text-white text-sm font-semibold transition-colors inline-flex items-center gap-1.5"
            >
              <Send size={ 14 } />
              { sending ? 'Sending…' : 'Send Update' }
            </button>
          </div>
        </div>
      </form>

      { error && <p className="text-xs text-red-600 mt-2" role="alert">{ error }</p> }
      { success && <p className="text-xs text-green-700 mt-2" role="status">{ success }</p> }

      <input
        ref={ libraryInputRef }
        type="file"
        accept=".jpg,.jpeg,.png,.gif,.webp"
        multiple
        className="hidden"
        onChange={ onSelectFiles }
      />
      <input
        ref={ cameraInputRef }
        type="file"
        accept="image/*"
        capture="environment"
        className="hidden"
        onChange={ onSelectFiles }
      />

    </div>
  );
}
