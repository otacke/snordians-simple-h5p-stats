import DataTable from 'datatables.net-dt';
import 'datatables.net-buttons-dt';
import './table-view.css';

/**
 * Send AJAX request to delete all data.
 * @param {string} nonce Nonce for delete action.
 * @param {string} [errorMessage] Optional error message override.
 */
const deleteData = async ( nonce, errorMessage ) => {
  try {
    const response = await fetch( simpleh5pstatsByDateDataTable.wpAJAXurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams( {
        action: 'simpleh5pstats_delete_data',
        nonce: nonce,
      } ),
    } );

    const text = await response.text();
    if ( '"done"' === text ) {
      location.reload();
    }
    else {
      alert( errorMessage || simpleh5pstatsByDateDataTable.errorMessage );
    }
  }
  catch {
    alert( errorMessage || simpleh5pstatsByDateDataTable.errorMessage );
  }
};

/**
 * Send all data as CSV via hidden form POST so browser handles file download.
 * @param {string} [actionName] AJAX action name. Defaults to detailed table action.
 * @param {string} nonce Nonce for download action.
 */
const downloadData = ( actionName, nonce ) => {
  const form = document.createElement( 'form' );
  form.method = 'post';
  form.action = simpleh5pstatsByDateDataTable.wpAJAXurl;

  const addField = ( name, value ) => {
    const field = document.createElement( 'input' );
    field.type = 'hidden';
    field.name = name;
    field.value = value;
    form.appendChild( field );
  };

  addField( 'action', actionName || 'simpleh5pstats_download_table_data' );
  addField( 'nonce', nonce );

  document.body.appendChild( form );
  form.submit();
  document.body.removeChild( form );
};

/**
 * Create button, optionally showing confirmation dialog.
 * @param {string} label Button label.
 * @param {function} action Called when user confirms (or immediately if no question).
 * @param {string} [question] Optional confirmation question.
 * @param {string} [tableId] Optional table ID for aria-controls.
 * @returns {HTMLElement} DOM button element.
 */
const createButton = ( label, action, question, tableId ) => {
  const button = document.createElement( 'button' );
  button.classList.add( 'dt-button' );
  button.setAttribute( 'aria-controls', tableId || 'simpleh5pstats-data-table' );
  button.innerText = label;

  button.addEventListener( 'click', ( event ) => {
    event.preventDefault();

    if ( !question ) {
      action();
    }
    else {
      const dialog = new SIMPLEH5PSTATSConfirmationDialog(
        {
          l10n: {
            message: question,
            cancel: simpleh5pstatsByDateDataTable.dialogCancelLabel || 'Cancel',
            confirm: simpleh5pstatsByDateDataTable.dialogConfirmLabel || 'OK',
          },
        },
        {
          onConfirm: () => {
            action();
            event.target.blur();
          },
        },
      );
      dialog.show();
    }
  } );

  return button;
};

/**
 * Escape HTML entities in string.
 * @param {string} text Plain text.
 * @returns {string} HTML-escaped string.
 */
const escapeHTML = ( text ) => {
  const span = document.createElement( 'span' );
  span.textContent = text;
  return span.innerHTML;
};

/**
 * Add delete button to DataTable button group.
 * @param {HTMLElement} buttonGroup DT button group container.
 * @param {object} config Localization config for table.
 */
const addButtonDelete = ( buttonGroup, config ) => {
  if ( buttonGroup && config.userCanDeleteResults === '1' ) {
    buttonGroup.appendChild(
      createButton(
        config.buttonLabelDelete,
        () => {
          deleteData( config.nonce, config.errorMessage );
        },
        config.dialogTextDelete,
        config.classDataTable,
      ),
    );
  }
};

/**
 * Add download button to DataTable button group.
 * @param {HTMLElement} buttonGroup DT button group container.
 * @param {object} config Localization config for table.
 */
const addButtonDownload = ( buttonGroup, config ) => {
  if ( buttonGroup && config.userCanDownloadResults === '1' ) {
    buttonGroup.appendChild(
      createButton(
        config.buttonLabelDownload,
        () => {
          downloadData( config.actionDownload, config.nonceDownloadTableData );
        },
        null,
        config.classDataTable,
      ),
    );
  }
};

/**
 * Build filter dropdown for single column.
 * @param {object} api DataTables API instance.
 * @param {number} colIndex Column index.
 * @param {object[]} options Array of distinct values from server.
 */
const buildColumnDropdown = ( api, colIndex, options ) => {
  const select = document.createElement( 'select' );
  select.classList.add( 'dt-input', 'simpleh5pstats-column-filter' );
  select.appendChild( document.createElement( 'option' ) );
  api.column( colIndex ).footer().replaceChildren( select );

  select.addEventListener( 'change', () => {
    api.column( colIndex ).search( select.value ).draw();
  } );

  options.forEach( ( val ) => {
    if ( null !== val && '' !== val ) {
      const option = document.createElement( 'option' );
      option.value = val;
      option.textContent = escapeHTML( val );
      select.appendChild( option );
    }
  } );
};

/**
 * Fetch distinct column values from server and build filter dropdowns.
 * @param {object} api DataTables API instance.
 * @param {string} actionName AJAX action name for column options.
 * @param {string} nonce Nonce for column options.
 * @param {number[]} filterableColumnIndices Indices of columns that should get filter.
 */
const buildColumnDropdowns = ( api, actionName, nonce, filterableColumnIndices ) => {
  ( async () => {
    try {
      const response = await fetch( simpleh5pstatsByDateDataTable.wpAJAXurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams( {
          action: actionName,
          nonce: nonce,
        } ),
      } );

      const data = await response.json();
      if ( ! data || ! data.success || ! data.data ) {
        return;
      }

      api.columns().every(
        ( colIndex ) => {
          if ( ! filterableColumnIndices.includes( colIndex ) ) {
            api.column( colIndex ).footer().replaceChildren();
            return;
          }
          const options = data.data[ colIndex ] || [];
          buildColumnDropdown( api, colIndex, options );
        },
      );
    }
    catch {
      // Silently fail — dropdowns just won't populate.
    }
  } )();
};

/**
 * Single DataTable-backed stats table (by-date or aggregated) and controls.
 */
class SimpleH5PStatsTableView {
  /**
   * Constructor.
   * @param {object} options Parameters.
   * @param {object} options.config Localized config object for this table.
   * @param {Array} options.columns DataTable column definitions.
   * @param {Array} options.order Default DataTable sort order.
   * @param {string} options.ajaxAction AJAX action name to fetch table data.
   * @param {string} options.columnOptionsAction AJAX action name to fetch column filter options.
   * @param {boolean} [options.withDelete] Whether to show delete button.
   * @param {Array} [options.filterableColumns] Data keys of columns that should get filter.
   */
  constructor( {
    config, columns, order, ajaxAction, columnOptionsAction, withDelete = false, filterableColumns = [],
  } ) {
    this.config = config;
    this.columns = columns;
    this.order = order;
    this.ajaxAction = ajaxAction;
    this.columnOptionsAction = columnOptionsAction;
    this.withDelete = withDelete;
    this.filterableColumns = filterableColumns;
  }

  /**
   * Initialize DataTable and controls.
   */
  init() {
    const { config, columns, order, ajaxAction, columnOptionsAction, withDelete, filterableColumns } = this;

    const filterableColumnIndices = columns
      .map( ( column, index ) => ( filterableColumns.includes( column.data ) ? index : null ) )
      .filter( ( index ) => null !== index );

    const datatableParams = {
      'dom': 'B<"simpleh5pstats-table-top-bar"lf>rt<"simpleh5pstats-table-bottom-bar"ip>',
      'buttons': [],
      'columns': columns,
      'order': order,
      'language': config.languageData,
      'serverSide': true,
      'processing': true,
      'ajax': {
        'url': config.wpAJAXurl,
        'type': 'POST',
        'data': ( d ) => {
          d.action = ajaxAction;
          d.nonce  = config.nonceGetTableData;
        },
      },
      'initComplete': function () {
        const api         = this.api();
        const buttonGroup = api.table().container().parentNode.querySelector( '.dt-buttons' );

        if ( withDelete ) {
          addButtonDelete( buttonGroup, config );
        }
        addButtonDownload( buttonGroup, config );
        buildColumnDropdowns( api, columnOptionsAction, config.nonceGetColumnOptions, filterableColumnIndices );
      },
    };

    new DataTable( `#${config.classDataTable}`, datatableParams );
  }
}

/**
 * Initialize both DataTables (aggregated and by-date).
 */
const initTables = () => {
  new SimpleH5PStatsTableView( {
    config: simpleh5pstatsAggregatedDataTable,
    columns: [
      { data: 'content_id',    title: simpleh5pstatsAggregatedDataTable.columnNames[0], width: '1%' },
      { data: 'content_title', title: simpleh5pstatsAggregatedDataTable.columnNames[1] },
      { data: 'total_hits',    title: simpleh5pstatsAggregatedDataTable.columnNames[2], width: '1%' },
    ],
    order: [[ simpleh5pstatsAggregatedDataTable.defaultOrderColumn, 'asc' ]],
    ajaxAction: 'simpleh5pstats_get_aggregated_table_data',
    columnOptionsAction: 'simpleh5pstats_get_aggregated_column_options',
    withDelete: true,
    filterableColumns: [ 'content_title' ],
  } ).init();

  new SimpleH5PStatsTableView( {
    config: simpleh5pstatsByDateDataTable,
    columns: [
      { data: 'content_id',    title: simpleh5pstatsByDateDataTable.columnNames[0], width: '1%' },
      { data: 'content_title', title: simpleh5pstatsByDateDataTable.columnNames[1] },
      { data: 'date',          title: simpleh5pstatsByDateDataTable.columnNames[2], width: '1%' },
      { data: 'hits',          title: simpleh5pstatsByDateDataTable.columnNames[3], width: '1%' },
    ],
    order: [[ simpleh5pstatsByDateDataTable.defaultOrderColumn, 'asc' ]],
    ajaxAction: 'simpleh5pstats_get_table_data',
    columnOptionsAction: 'simpleh5pstats_get_column_options',
    withDelete: false,
    filterableColumns: [ 'content_title', 'date' ],
  } ).init();
};

// Initialize both DataTables once the DOM is ready.
if ( 'interactive' === document.readyState ) {
  initTables();
}
else {
  document.addEventListener( 'DOMContentLoaded', initTables );
}
