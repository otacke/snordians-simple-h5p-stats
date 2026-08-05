import { createUUID } from '@services/util.js';

/** @constant {string} SNORDIANSSIMPLEH5PSTATS_USER_UUID_KEY Key for custom local storage key. */
const SNORDIANSSIMPLEH5PSTATS_USER_UUID_KEY = 'SNORDIANSSIMPLEH5PSTATSUserUUID';

/** @constant {string} H5P_USER_UUID_KEY Key for H5P's local storage key. */
const H5P_USER_UUID_KEY = 'H5PUserUUID';

/** @constant {number} TIMEOUT_MS Timeout for waiting for H5P core to set user id. */
const TIMEOUT_WAIT_FOR_H5P_UUID_MS = 3000;

window.H5P = window.H5P || {};

/**
 * Listener that reports H5P content views back to the WordPress backend.
 */
class SimpleH5PStatsListener {
  /**
   * Constructor.
   */
  constructor() {
    this.topWindow = ( window.SimpleH5PStats ) ? window : this.getTopWindow();
    if ( ! this.topWindow || ! this.topWindow.SimpleH5PStats ) {
      // eslint-disable-next-line @stylistic/js/max-len
      console.warn('Could not find SimpleH5PStats object, cannot store hits. Potentially, the plugin directory is not writable by the server.' );
      return;
    }
    this.simpleH5PStats = this.topWindow.SimpleH5PStats;

    if ( 'complete' === this.topWindow.document.readyState ) {
      this.handleReady();
    }
    else {
      const onReady = () => {
        if ( 'complete' === this.topWindow.document.readyState ) {
          this.topWindow.document.removeEventListener( 'readystatechange', onReady );
          this.handleReady();
        }
      };
      this.topWindow.document.addEventListener( 'readystatechange', onReady );
    }
  }

  /**
   * Get top DOM window object.
   * @param {Window} startWindow Window to start looking from.
   * @returns {Window|false} Top window.
   */
  getTopWindow( startWindow = window ) {
    let sameOrigin;

    try {
      sameOrigin = startWindow.parent.location.host === window.location.host;
    }
    catch ( error ) {
      sameOrigin = false;
    }

    if ( ! sameOrigin ) {
      return false;
    }

    if ( startWindow.parent === startWindow || ! startWindow.parent ) {
      return startWindow;
    }

    return this.getTopWindow( startWindow.parent );
  }

  /**
   * Send an AJAX request to record a hit.
   * @param {number} contentId H5P content ID.
   * @param {string} [userUUID] User's UUID if available.
   */
  sendAJAX( contentId, userUUID ) {
    fetch( this.simpleH5PStats.wpAJAXurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams( {
        action: 'simpleh5pstats_insert_data',
        content_id: contentId,
        user_uuid: userUUID,
        nonce: this.getNonceFromScriptSource(),
      } ),
    } );
  }

  /**
   * Decode a query string that has been double-encoded by WP, URL encoding and HTML encoding.
   * @param {string} encoded Query string.
   * @returns {string} Decoded query string.
   */
  decodeDoubleEncodedQuery( encoded ) {
    return decodeURIComponent( decodeURIComponent( encoded ) );
  }

  /**
   * Get nonce from script tag source URL.
   * @returns {string|null} Nonce string or null.
   */
  getNonceFromScriptSource() {
    const scripts =
      [...this.topWindow.document.getElementsByTagName( 'script' ), ...document.getElementsByTagName( 'script' )];

    for ( let i = 0; i < scripts.length; i++ ) {
      const src = scripts[i].src;
      if ( src.includes( 'simpleh5pstats-listener.js' ) ) {
        const query = src.split( '?' )[1];
        if ( query ) {
          const urlParams = new URLSearchParams( this.decodeDoubleEncodedQuery( query ) );
          return urlParams.get( 'nonce' );
        }
      }
    }
    return null;
  }

  /**
   * Get a custom UUID from localStorage, generating and storing one if needed.
   * @returns {string|undefined} The custom UUID or undefined.
   */
  useCustomUUID() {
    let uuid;

    try {
      uuid = localStorage.getItem( SNORDIANSSIMPLEH5PSTATS_USER_UUID_KEY );
    }
    catch ( error ) {
      // intentionally left blank.
    }

    if ( ! uuid ) {
      uuid = this.createUUID();
      try {
        localStorage.setItem( SNORDIANSSIMPLEH5PSTATS_USER_UUID_KEY, uuid );
      }
      catch ( error ) {
        // Intentionally left blank
      }
    }

    return uuid;
  }

  /**
   * Get H5P user UUID from localStorage, returning undefined on failure.
   * @returns {string|undefined} The H5P user UUID or undefined.
   */
  getH5PUserUUID() {
    try {
      return localStorage.getItem( H5P_USER_UUID_KEY );
    }
    catch ( error ) {
      return;
    }
  }

  /**
   * Get the H5P user UUID, waiting for initialization if needed.
   * @returns {Promise<string|undefined>} The H5P user UUID or a random ID.
   */
  async getUserUUID() {
    if ( this.topWindow.H5P.instances?.length ) {
      return this.getH5PUserUUID();
    }
    return new Promise( ( resolve ) => {
      const timer = setTimeout( () => {
        resolve( this.useCustomUUID() );
      }, TIMEOUT_WAIT_FOR_H5P_UUID_MS );

      this.topWindow.H5P.externalDispatcher.once( 'initialized', () => {
        clearTimeout( timer );
        resolve( this.getH5PUserUUID() );
      } );
    } );
  }

  /**
   * Send hits for all H5P content on the page once ready.
   */
  async handleReady() {
    const h5pDDIVs = [...this.topWindow.document.body.querySelectorAll( '.h5p-content' )];
    const contentIDs = h5pDDIVs.map( ( div ) => div.getAttribute( 'data-content-id' ) );

    const userUUID = await this.getUserUUID();

    contentIDs.forEach( ( contentID ) => {
      this.sendAJAX( parseInt( contentID, 10 ), userUUID );
    } );
  }
}

new SimpleH5PStatsListener();
