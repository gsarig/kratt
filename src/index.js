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
import { createBlock } from '@wordpress/blocks';
import { Button, TextareaControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __, _n, sprintf } from '@wordpress/i18n';

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
			// Build a numbered block summary for positional context.
			const editorContent = blocks.length
				? blocks
					.map( ( block, i ) => {
						let line = `[${ i }] ${ block.name }`;
						const raw =
							block.attributes?.content ||
							block.attributes?.value ||
							block.attributes?.caption ||
							block.attributes?.label ||
							'';
						const text = raw.replace( /<[^>]+>/g, '' ).trim();
						if ( text ) {
							line += `: "${ text.length > 80 ? text.slice( 0, 80 ) + '…' : text }"`;
						}
						return line;
					} )
					.join( '\n' )
				: '';

			// Collect allowed blocks from editor settings.
			const { getSettings } = wp.data.select( blockEditorStore );
			const allowedBlockTypes = getSettings().allowedBlockTypes;
			const allowedBlocks =
				Array.isArray( allowedBlockTypes ) ? allowedBlockTypes
				: allowedBlockTypes === false ? []
				: null;

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
				addMessage( 'assistant', __( 'No blocks were returned.', 'kratt' ), true );
				return;
			}

			function buildBlock( spec ) {
				const inner = ( spec.innerBlocks || [] ).map( buildBlock );
				return createBlock( spec.name, spec.attributes || {}, inner );
			}

			const parsedBlocks = response.blocks.map( buildBlock );

			let { index, rootClientId } = getInsertionPoint();
			if ( typeof response.insertBefore === 'number' && response.insertBefore >= 0 && response.insertBefore < blocks.length ) {
				index = response.insertBefore;
				rootClientId = undefined;
			} else if ( typeof response.insertAfter === 'number' && response.insertAfter >= 0 && response.insertAfter < blocks.length ) {
				index = response.insertAfter + 1;
				rootClientId = undefined;
			}
			insertBlocks( parsedBlocks, index, rootClientId );

			const summary = `Added ${ parsedBlocks.length } block${ parsedBlocks.length !== 1 ? 's' : '' } to the editor.`;
			addMessage( 'assistant', summary, false, response.note ?? null );
		} catch ( error ) {
			addMessage( 'assistant', error?.message ?? __( 'Something went wrong.', 'kratt' ), true );
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
		<PluginSidebar name="kratt-sidebar" title={ __( 'Kratt', 'kratt' ) } icon={ KrattIcon }>
			<div className="kratt-sidebar">
				<div className="kratt-messages">
					{ messages.length === 0 && (
						<p className="kratt-empty-state">
							{ __( "Describe the blocks you'd like to add to the editor.", 'kratt' ) }
							<br />
							<em>{ __( 'Example: "Add a hero, then an FAQ section."', 'kratt' ) }</em>
						</p>
					) }
					{ olderMessages.length > 0 && (
						<details className="kratt-history">
							<summary className="kratt-history__toggle">
								{ sprintf(
								/* translators: %d: number of previous messages */
								_n( '%d previous message', '%d previous messages', olderMessages.length, 'kratt' ),
								olderMessages.length
							) }
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
						placeholder={ __( 'Describe what you want to build…', 'kratt' ) }
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
						{ __( 'Generate', 'kratt' ) }
					</Button>
				</div>
			</div>
		</PluginSidebar>
	);
}

registerPlugin( 'kratt', {
	render: KrattSidebar,
} );
