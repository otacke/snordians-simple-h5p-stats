/** @constant {string} CLASS_CHECKBOX_EMBED_ALLOWED Class for embed allowed checkbox. */
const CLASS_CHECKBOX_EMBED_ALLOWED = 'embed_supported';

/**
 * Settings page behavior for plugin's options screen.
 */
class SimpleH5PStatsOptionsPage {
  /**
   * Constructor.
   */
  constructor() {
    document.addEventListener( 'readystatechange', () => {
      if ( 'interactive' !== document.readyState ) {
        return;
      }

      this.wireEmbedAllowedCheckbox();
    } );
  }

  /**
   * Warn user before enabling allow-embed option.
   */
  wireEmbedAllowedCheckbox() {
    /** @type {Element|null} Embed allowed checkbox element. */
    const embedAllowed = document.getElementById( CLASS_CHECKBOX_EMBED_ALLOWED );
    if ( null === embedAllowed ) {
      return;
    }

    embedAllowed.addEventListener(
      'click',
      () => {
        if ( ! embedAllowed.checked ) {
          return;
        }

        const dialog = new SIMPLEH5PSTATSConfirmationDialog(
          {
            l10n: {
              message: simpleh5pstatsOptions.l10n.embedAllowedWarning,
              cancel: simpleh5pstatsOptions.l10n.embedAllowedCancel,
              confirm: simpleh5pstatsOptions.l10n.embedAllowedConfirm,
            },
          },
          {
            onCancel: () => {
              embedAllowed.checked = false;
            },
            onConfirm: () => {
              embedAllowed.checked = true;
            },
          },
        );
        dialog.show();
      },
    );
  }
}

new SimpleH5PStatsOptionsPage();
