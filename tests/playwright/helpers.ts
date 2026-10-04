// tests/playwright/helpers.ts
import { Page, expect } from '@playwright/test';

// Users created by scripts/wp-test-setup.sh; each one's login is its role name.
export const ROLES = [ 'admin', 'editor', 'author', 'contributor', 'subscriber' ] as const;
export type Role = ( typeof ROLES )[ number ];

const PASSWORD = 'password';

export async function loginAs( page: Page, role: Role ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', role );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/, { timeout: 15_000 } );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 15_000 } );
}

// REST nonce for the logged-in user, so page.request calls authenticate by cookie.
export async function restNonce( page: Page ): Promise< string > {
	const response = await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' );
	expect( response.ok() ).toBe( true );
	return ( await response.text() ).trim();
}

export async function restPost( page: Page, route: string, data: object, nonce?: string ) {
	return page.request.post( `/?rest_route=${ route }`, {
		headers: nonce ? { 'X-WP-Nonce': nonce } : {},
		data,
	} );
}

// Opens a new post with the welcome guide off and the Kratt sidebar shown.
export async function openKrattSidebar( page: Page ) {
	await page.goto( '/wp-admin/post-new.php', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => ( window as any ).wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	await page.evaluate( () => {
		const { data } = ( window as any ).wp;
		data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'kratt/kratt-sidebar' );
	} );
	await expect( page.locator( '.kratt-sidebar' ) ).toBeVisible( { timeout: 15_000 } );
}
