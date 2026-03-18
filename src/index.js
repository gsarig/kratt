import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { parse, serialize } from '@wordpress/blocks';
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

			const response = await apiFetch( {
				path: '/kratt/v1/compose',
				method: 'POST',
				data: {
					prompt,
					editor_content: editorContent,
					...(allowedBlocks ? { allowed_blocks: allowedBlocks } : {}),
				},
			} );

			if ( response.error ) {
				addMessage( 'assistant', response.error, true, response.suggestion ?? null );
				return;
			}

			if ( ! response.markup ) {
				addMessage( 'assistant', 'No blocks were returned.', true );
				return;
			}

			const parsedBlocks = parse( response.markup );

			if ( ! parsedBlocks.length ) {
				addMessage( 'assistant', 'The response could not be parsed into valid blocks.', true );
				return;
			}

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

	return (
		<PluginSidebar name="kratt-sidebar" title="Kratt" icon="admin-generic">
			<div className="kratt-sidebar">
				<div className="kratt-messages">
					{ messages.length === 0 && (
						<p className="kratt-empty-state">
							Describe the blocks you'd like to add to the editor.
							<br />
							<em>Example: "Add a hero, then an FAQ section."</em>
						</p>
					) }
					{ messages.map( ( message, i ) => (
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
					) ) }
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
