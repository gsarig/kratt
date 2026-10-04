/**
 * Role boundaries: one allowed and one refused case for every capability check in Kratt.
 * A new current_user_can() call in includes/ needs a row here.
 *
 * - edit_posts (compose, review): admin, editor, author and contributor generate and
 *   review from the sidebar; a subscriber is refused post-new.php and gets 403 from both
 *   endpoints; a logged-out visitor gets 403 too (Kratt's own check returns 403, not 401).
 *   The kratt/insert-block ability shares this check but is not exposed over REST, so
 *   tests/phpunit/integration/Abilities/InsertBlockAbilityTest.php covers it.
 * - edit_post (post context): a post_id the user can't edit is not refused; Kratt drops
 *   the post context and still answers, so the contributor row below expects 200.
 * - manage_options (catalog read and rescan, Settings > Kratt): admin allowed, every
 *   other role 403 or refused.
 *
 * Kratt has no front-end output, so there are no visitor page checks.
 */
import { test, expect, Page } from '@playwright/test';
import { ROLES, Role, loginAs, openKrattSidebar, restNonce, restPost } from './helpers';

const EDITORS: Role[] = [ 'admin', 'editor', 'author', 'contributor' ];
const PROMPT = 'Add a hero';

async function adminPostId( page: Page ): Promise< number > {
	const response = await page.request.get( '/?rest_route=/wp/v2/posts&slug=kratt-admin-post' );
	expect( response.ok() ).toBe( true );
	const posts = await response.json();
	expect( Array.isArray( posts ) && posts.length ).toBeTruthy();
	return posts[ 0 ].id;
}

for ( const role of EDITORS ) {
	test( `[${ role }] generates and reviews blocks from the sidebar`, async ( { page } ) => {
		await loginAs( page, role );
		await openKrattSidebar( page );

		const sidebar = page.locator( '.kratt-sidebar' );
		await sidebar.getByRole( 'textbox' ).fill( PROMPT );
		await sidebar.getByRole( 'button', { name: 'Generate' } ).click();
		await expect( sidebar ).toContainText( 'Added 2 blocks to the editor.' );
		// Match on text: an editable heading's accessible name is its block label.
		await expect(
			page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'h2', { hasText: `Test mode: "${ PROMPT }"` } )
		).toBeVisible();

		await sidebar.getByRole( 'button', { name: 'Review' } ).click();
		await expect( sidebar.locator( '.kratt-findings' ) ).toContainText( 'Heading hierarchy gap detected.' );
	} );
}

for ( const role of ROLES ) {
	const canEdit = role !== 'subscriber';
	const canManage = role === 'admin';

	test( `[${ role }] REST: compose and review ${ canEdit ? 200 : 403 }, catalog ${ canManage ? 200 : 403 }`, async ( { page } ) => {
		await loginAs( page, role );
		const nonce = await restNonce( page );

		expect( ( await restPost( page, '/kratt/v1/compose', { prompt: PROMPT }, nonce ) ).status() ).toBe( canEdit ? 200 : 403 );
		expect( ( await restPost( page, '/kratt/v1/review', { editor_content: '' }, nonce ) ).status() ).toBe( canEdit ? 200 : 403 );

		const catalog = await page.request.get( '/?rest_route=/kratt/v1/catalog', { headers: { 'X-WP-Nonce': nonce } } );
		expect( catalog.status() ).toBe( canManage ? 200 : 403 );
		expect( ( await restPost( page, '/kratt/v1/catalog/rescan', {}, nonce ) ).status() ).toBe( canManage ? 200 : 403 );
	} );

	test( `[${ role }] Settings > Kratt is ${ canManage ? 'shown' : 'refused' }`, async ( { page } ) => {
		await loginAs( page, role );
		const response = await page.goto( '/wp-admin/options-general.php?page=kratt' );
		if ( canManage ) {
			await expect( page.getByRole( 'heading', { level: 1, name: 'Kratt' } ) ).toBeVisible();
		} else {
			expect( response?.status() ).toBe( 403 );
		}
	} );
}

test( '[subscriber] is refused the post editor', async ( { page } ) => {
	await loginAs( page, 'subscriber' );
	const response = await page.goto( '/wp-admin/post-new.php' );
	expect( response?.status() ).toBe( 403 );
	await expect( page.locator( '.kratt-sidebar' ) ).toHaveCount( 0 );
} );

test( '[visitor] gets 403 from compose, review and catalog', async ( { page } ) => {
	expect( ( await restPost( page, '/kratt/v1/compose', { prompt: PROMPT } ) ).status() ).toBe( 403 );
	expect( ( await restPost( page, '/kratt/v1/review', { editor_content: '' } ) ).status() ).toBe( 403 );
	expect( ( await page.request.get( '/?rest_route=/kratt/v1/catalog' ) ).status() ).toBe( 403 );
} );

test( '[contributor] composing with the admin\'s post_id is answered without its context', async ( { page } ) => {
	await loginAs( page, 'contributor' );
	const nonce = await restNonce( page );
	const response = await restPost( page, '/kratt/v1/compose', { prompt: PROMPT, post_id: await adminPostId( page ) }, nonce );

	expect( response.status() ).toBe( 200 );
	expect( ( await response.json() ).blocks?.length ).toBe( 2 );
} );
