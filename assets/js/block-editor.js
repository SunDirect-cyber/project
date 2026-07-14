/**
 * Editor script for the "wcmcs/currency-switcher" block. Plain
 * wp.element.createElement calls (no JSX) since there's no
 * webpack/@wordpress/scripts build step in this plugin — this file is
 * enqueued and runs as-is.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'wcmcs/currency-switcher', {
		title: __( 'Currency Switcher', 'wc-multicurrency-switcher' ),
		description: __( 'Lets shoppers switch the store currency.', 'wc-multicurrency-switcher' ),
		icon: 'money-alt',
		category: 'woocommerce',
		attributes: {
			style: { type: 'string', default: 'dropdown' },
			showFlag: { type: 'boolean', default: true },
			showCode: { type: 'boolean', default: true },
			showSymbol: { type: 'boolean', default: false },
			showName: { type: 'boolean', default: false }
		},

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				wp.element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Currency Switcher Settings', 'wc-multicurrency-switcher' ) },
						el( SelectControl, {
							label: __( 'Style', 'wc-multicurrency-switcher' ),
							value: attributes.style,
							options: [
								{ label: __( 'Dropdown', 'wc-multicurrency-switcher' ), value: 'dropdown' },
								{ label: __( 'Button list', 'wc-multicurrency-switcher' ), value: 'button_list' },
								{ label: __( 'Flag grid', 'wc-multicurrency-switcher' ), value: 'flag_grid' }
							],
							onChange: function ( value ) { setAttributes( { style: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show flag', 'wc-multicurrency-switcher' ),
							checked: attributes.showFlag,
							onChange: function ( value ) { setAttributes( { showFlag: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show currency code', 'wc-multicurrency-switcher' ),
							checked: attributes.showCode,
							onChange: function ( value ) { setAttributes( { showCode: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show currency symbol', 'wc-multicurrency-switcher' ),
							checked: attributes.showSymbol,
							onChange: function ( value ) { setAttributes( { showSymbol: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show currency name', 'wc-multicurrency-switcher' ),
							checked: attributes.showName,
							onChange: function ( value ) { setAttributes( { showName: value } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'wcmcs/currency-switcher',
					attributes: attributes
				} )
			);
		},

		// Dynamic block: PHP render_callback supplies the frontend markup.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
