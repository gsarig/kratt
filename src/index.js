import './index.css';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';

const KrattIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
		{ /* Straw / twigs sprouting from top of head */ }
		<line x1="13" y1="3.5" x2="11.5" y2="1.5" />
		<line x1="14.5" y1="3" x2="14.5" y2="1" />
		<line x1="16" y1="3.5" x2="17.5" y2="1.5" />
		<line x1="16.5" y1="4.5" x2="18.5" y2="3.5" />
		{ /* Head */ }
		<circle cx="14.5" cy="5.5" r="2" />
		{ /* Body: two diagonal crossed sticks forming the torso */ }
		<line x1="14" y1="7.5" x2="11" y2="14.5" />
		<line x1="16" y1="8.5" x2="9" y2="13.5" />
		{ /* Left arm extending to carry a plank */ }
		<line x1="10.5" y1="11.5" x2="3.5" y2="9.5" />
		{ /* Flat board / plank at the end of the arm */ }
		<path d="M1.5 8.5 L4.5 8 L5 10 L2 10.5 Z" fill="currentColor" stroke="none" />
		{ /* Hip bundle where legs attach */ }
		<circle cx="11" cy="14.5" r="1" fill="currentColor" stroke="none" />
		{ /* Three spindly legs */ }
		<line x1="11" y1="15.5" x2="5" y2="23" />
		<line x1="11" y1="15.5" x2="10" y2="23" />
		<line x1="11" y1="15.5" x2="17.5" y2="22.5" />
	</svg>
);
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { createBlock, serialize } from '@wordpress/blocks';
import { Button, TextareaControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { store as blockEditorStore } from '@wordpress/block-editor';

function KrattSidebar() {
	const [ messages, setMessages ] = useState( [] );
	const [ input, setInput ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	const { blocks, selectedBlockClientId } = useSelect( ( select ) => ( {
		blocks: select( blockEditorStore ).getBlocks(),
		selectedBlockClientId: select( blockEditorStore ).getSelectedBlockClientId(),
	} ) );

	const { insertBlocks } = useDispatch( blockEditorStore );

	function getInsertionPoint() {
		const { getBlockIndex, getBlockRootClientId } =
			wp.data.select( blockEditorStore );
		if ( ! selectedBlockClientId ) {
			return { index: undefined, rootClientId: undefined };
		}
		return {
			index: getBlockIndex( selectedBlockClientId ) + 1,
			rootClientId: getBlockRootClientId( selectedBlockClientId ),
		};
	}

	function addMessage( role, content, isError = false, suggestion = null ) {
		setMessages( ( prev ) => [
			...prev,
			{ role, content, isError, suggestion },
		] );
	}

	async function handleSubmit() {
		const prompt = input.trim();
		if ( ! prompt || isLoading ) return;

		setInput( '' );
		addMessage( 'user', prompt );
		setIsLoading( true );

		try {
			// Serialize current editor content as read-only context.
			const editorContent = serialize( blocks );

			// Collect allowed blocks from editor settings.
			const { getSettings } = wp.data.select( blockEditorStore );
			const allowedBlockTypes = getSettings().allowedBlockTypes;
			const allowedBlocks =
				Array.isArray( allowedBlockTypes ) ? allowedBlockTypes : null;

			// Post context — post_type is always available even for unsaved posts.
			const { getCurrentPostId, getCurrentPostType } = wp.data.select( 'core/editor' );
			const postId   = getCurrentPostId() || 0;
			const postType = getCurrentPostType() || '';

			const response = await apiFetch( {
				path: '/kratt/v1/compose',
				method: 'POST',
				data: {
					prompt,
					editor_content: editorContent,
					post_id: postId,
					post_type: postType,
					...(allowedBlocks ? { allowed_blocks: allowedBlocks } : {}),
				},
			} );

			if ( response.error ) {
				addMessage( 'assistant', response.error, true, response.suggestion ?? null );
				return;
			}

			if ( ! Array.isArray( response.blocks ) || ! response.blocks.length ) {
				addMessage( 'assistant', 'No blocks were returned.', true );
				return;
			}

			function buildBlock( spec ) {
				const inner = ( spec.innerBlocks || [] ).map( buildBlock );
				return createBlock( spec.name, spec.attributes || {}, inner );
			}

			const parsedBlocks = response.blocks.map( buildBlock );

			const { index, rootClientId } = getInsertionPoint();
			insertBlocks( parsedBlocks, index, rootClientId );

			addMessage(
				'assistant',
				`Added ${ parsedBlocks.length } block${ parsedBlocks.length !== 1 ? 's' : '' } to the editor.`
			);
		} catch ( error ) {
			addMessage( 'assistant', error?.message ?? 'Something went wrong.', true );
		} finally {
			setIsLoading( false );
		}
	}

	function handleKeyDown( event ) {
		if ( event.key === 'Enter' && ! event.shiftKey ) {
			event.preventDefault();
			handleSubmit();
		}
	}

	const recentMessages = messages.slice( -2 );
	const olderMessages = messages.slice( 0, -2 );

	function renderMessage( message, i ) {
		return (
			<div
				key={ i }
				className={ [
					'kratt-message',
					`kratt-message--${ message.role }`,
					message.isError ? 'kratt-message--error' : '',
				]
					.filter( Boolean )
					.join( ' ' ) }
			>
				<p>{ message.content }</p>
				{ message.suggestion && (
					<p className="kratt-message__suggestion">
						{ message.suggestion }
					</p>
				) }
			</div>
		);
	}

	return (
		<PluginSidebar name="kratt-sidebar" title="Kratt" icon={ KrattIcon }>
			<div className="kratt-sidebar">
				<div className="kratt-messages">
					{ messages.length === 0 && (
						<p className="kratt-empty-state">
							Describe the blocks you'd like to add to the editor.
							<br />
							<em>Example: "Add a hero, then an FAQ section."</em>
						</p>
					) }
					{ olderMessages.length > 0 && (
						<details className="kratt-history">
							<summary className="kratt-history__toggle">
								{ olderMessages.length } previous { olderMessages.length === 1 ? 'message' : 'messages' }
							</summary>
							<div className="kratt-history__messages">
								{ olderMessages.map( renderMessage ) }
							</div>
						</details>
					) }
					{ recentMessages.map( renderMessage ) }
					{ isLoading && (
						<div className="kratt-loading">
							<Spinner />
						</div>
					) }
				</div>

				<div className="kratt-input-area">
					<TextareaControl
						value={ input }
						onChange={ setInput }
						onKeyDown={ handleKeyDown }
						placeholder="Add a hero with a heading, then an FAQ…"
						rows={ 3 }
						disabled={ isLoading }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="primary"
						onClick={ handleSubmit }
						disabled={ ! input.trim() || isLoading }
						className="kratt-submit"
					>
						Generate
					</Button>
				</div>
			</div>
		</PluginSidebar>
	);
}

registerPlugin( 'kratt', {
	render: KrattSidebar,
} );
