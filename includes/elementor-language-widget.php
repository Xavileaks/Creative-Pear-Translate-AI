<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Elementor_Language_Widget extends \Elementor\Widget_Base {
    public function get_name(): string { return 'cptai-language-switcher'; }
    public function get_title(): string { return 'CPT AI · Selector de idiomas'; }
    public function get_icon(): string { return 'eicon-globe'; }
    public function get_categories(): array { return array( 'general' ); }
    public function get_keywords(): array { return array( 'idioma', 'bandera', 'language', 'translate', 'creative pear' ); }
    public function get_style_depends(): array { return array( 'cptai-switcher' ); }
    protected function is_dynamic_content(): bool { return true; }

    protected function register_controls(): void {
        $this->start_controls_section( 'languages', array( 'label' => 'Idiomas', 'tab' => \Elementor\Controls_Manager::TAB_CONTENT ) );
        $names = CPTAI_Settings::languages();
        $active = array_map( static fn( $code ) => $names[ $code ], CPTAI_Routing::languages() );
        $this->add_control( 'active_languages', array( 'type' => \Elementor\Controls_Manager::RAW_HTML, 'raw' => 'Idiomas activos en CPT AI: ' . esc_html( implode( ', ', $active ) ) . '. Cambia la selección en CPT AI → Ajustes; el widget recoge esos idiomas automáticamente.', 'content_classes' => 'elementor-panel-alert elementor-panel-alert-info' ) );
        $this->add_control( 'display', array( 'label' => 'Mostrar', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'flags_name', 'options' => array( 'flags' => 'Solo banderas', 'flags_code' => 'Banderas e iniciales', 'flags_name' => 'Banderas y nombre completo', 'name' => 'Solo nombre completo', 'code' => 'Solo iniciales' ) ) );
        $this->add_control( 'en_flag', array( 'label' => 'Bandera para inglés', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'us', 'options' => array( 'us' => 'Estados Unidos', 'gb' => 'Reino Unido', 'gb-eng' => 'Inglaterra' ), 'condition' => array( 'display' => array( 'flags', 'flags_code', 'flags_name' ) ) ) );
        $this->add_control( 'pt_flag', array( 'label' => 'Bandera para portugués', 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'pt', 'options' => array( 'pt' => 'Portugal', 'br' => 'Brasil' ), 'condition' => array( 'display' => array( 'flags', 'flags_code', 'flags_name' ) ) ) );
        $this->end_controls_section();

        $this->start_controls_section( 'layout', array( 'label' => 'Distribución y banderas', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_responsive_control( 'alignment', array( 'label' => 'Alineación', 'type' => \Elementor\Controls_Manager::CHOOSE, 'options' => array( 'flex-start' => array( 'title' => 'Inicio', 'icon' => 'eicon-text-align-left' ), 'center' => array( 'title' => 'Centro', 'icon' => 'eicon-text-align-center' ), 'flex-end' => array( 'title' => 'Final', 'icon' => 'eicon-text-align-right' ) ), 'selectors' => array( '{{WRAPPER}} .cptai-elementor-switcher' => 'justify-content: {{VALUE}};' ) ) );
        $this->add_responsive_control( 'language_gap', array( 'label' => 'Separación entre idiomas', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em', 'rem' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 160 ) ), 'default' => array( 'unit' => 'px', 'size' => 16 ), 'selectors' => array( '{{WRAPPER}} .cptai-elementor-switcher' => 'gap: {{SIZE}}{{UNIT}};' ) ) );
        $this->add_responsive_control( 'flag_size', array( 'label' => 'Tamaño de la bandera', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em', 'rem' ), 'range' => array( 'px' => array( 'min' => 8, 'max' => 160 ) ), 'default' => array( 'unit' => 'px', 'size' => 32 ), 'selectors' => array( '{{WRAPPER}} .cptai-flag' => 'width: {{SIZE}}{{UNIT}};' ), 'condition' => array( 'display' => array( 'flags', 'flags_code', 'flags_name' ) ) ) );
        $this->add_responsive_control( 'flag_text_gap', array( 'label' => 'Separación bandera y texto', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', 'em' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 64 ) ), 'default' => array( 'unit' => 'px', 'size' => 8 ), 'selectors' => array( '{{WRAPPER}} .cptai-language' => 'gap: {{SIZE}}{{UNIT}};' ), 'condition' => array( 'display' => array( 'flags_code', 'flags_name' ) ) ) );
        $this->add_control( 'flag_radius', array( 'label' => 'Esquinas de las banderas', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .cptai-flag' => 'border-radius: {{SIZE}}{{UNIT}};' ), 'condition' => array( 'display' => array( 'flags', 'flags_code', 'flags_name' ) ) ) );
        $this->end_controls_section();

        $this->start_controls_section( 'text_style', array( 'label' => 'Texto', 'tab' => \Elementor\Controls_Manager::TAB_STYLE, 'condition' => array( 'display!' => 'flags' ) ) );
        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'typography', 'selector' => '{{WRAPPER}} .cptai-language-label' ) );
        $this->add_group_control( \Elementor\Group_Control_Text_Shadow::get_type(), array( 'name' => 'text_shadow', 'selector' => '{{WRAPPER}} .cptai-language-label' ) );
        $this->start_controls_tabs( 'colors' );
        foreach ( array( 'normal' => 'Normal', 'hover' => 'Al pasar el cursor', 'active' => 'Idioma activo' ) as $state => $label ) {
            $this->start_controls_tab( 'color_' . $state, array( 'label' => $label ) );
            $selector = '{{WRAPPER}} .cptai-language' . ( 'normal' === $state ? '' : ( 'hover' === $state ? ':hover' : '.is-active' ) ) . ' .cptai-language-label';
            $this->add_control( 'text_color_' . $state, array( 'label' => 'Color', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( $selector => 'color: {{VALUE}};' ) ) );
            $this->end_controls_tab();
        }
        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function render(): void {
        echo CPTAI_Elementor::render( $this->get_settings_for_display() ); // Output escaped in renderer.
    }
}
