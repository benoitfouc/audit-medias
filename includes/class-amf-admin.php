<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMF_Admin {
	/** @var AMF_Database */
	private $db;
	/** @var AMF_Scanner */
	private $scanner;
	/** @var AMF_Actions */
	private $actions;
	/** @var wpdb */
	private $wpdb;
	private $product_usage;
	private $usage_view;

	public function __construct( AMF_Database $db, AMF_Scanner $scanner, AMF_Actions $actions, AMF_Product_Usage $product_usage ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->actions = $actions;
		$this->product_usage = $product_usage;
		$this->usage_view = new AMF_Usage_View( $db, $product_usage );
		global $wpdb;
		$this->wpdb = $wpdb;

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_amf_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_amf_resume_scan', array( $this, 'ajax_resume_scan' ) );
		add_action( 'wp_ajax_amf_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_amf_scan_status', array( $this, 'ajax_scan_status' ) );
		add_action( 'wp_ajax_amf_save_performance_settings', array( $this, 'ajax_save_performance_settings' ) );
		add_action( 'wp_ajax_amf_save_deletion_settings', array( $this, 'ajax_save_deletion_settings' ) );
		add_action( 'wp_ajax_amf_product_index', array( $this, 'ajax_product_index' ) );
		add_action( 'wp_ajax_amf_family_files', array( $this, 'ajax_family_files' ) );
		add_action( 'wp_ajax_amf_family_details', array( $this, 'ajax_family_details' ) );
		add_action( 'wp_ajax_amf_delete_media', array( $this, 'ajax_delete_media' ) );
		add_action( 'wp_ajax_amf_delete_family', array( $this, 'ajax_delete_family' ) );
		add_action( 'wp_ajax_amf_refresh_woo_image_usage', array( $this, 'ajax_refresh_woo_image_usage' ) );
	}

	public function admin_menu() {
		add_management_page(
			'Audit Médias',
			'Audit Médias',
			'manage_options',
			AMF_PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . AMF_PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'amf-admin', AMF_PLUGIN_URL . 'assets/admin.css', array(), AMF_VERSION );
		wp_enqueue_style( 'amf-admin-tables', AMF_PLUGIN_URL . 'assets/admin-tables.css', array( 'amf-admin' ), AMF_VERSION );
		wp_enqueue_style( 'amf-client', AMF_PLUGIN_URL . 'assets/admin-client.css', array( 'amf-admin-tables' ), AMF_VERSION );
		wp_enqueue_style( 'amf-brand', AMF_PLUGIN_URL . 'assets/admin-brand.css', array( 'amf-client' ), AMF_VERSION );
		wp_enqueue_script( 'amf-admin', AMF_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), AMF_VERSION, true );
		wp_localize_script(
			'amf-admin',
			'AMF_DATA',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'amf_scan_nonce' ),
				'status'           => $this->scanner->progress_payload(),
				'performance'      => $this->scanner->get_performance_settings(),
				'presets'          => $this->scanner->performance_presets(),
				'productIndex'     => $this->product_usage->status(),
				'deletionEnabled'  => $this->actions->deletion_enabled(),
				'i18n'             => array(
					'confirmRestart'     => 'Recommencer l’analyse effacera uniquement les résultats de scan du plugin. Aucun média ni fichier uploads ne sera modifié. Continuer ?',
					'error'              => 'Une erreur est survenue. Vous pouvez reprendre l’analyse.',
					'confirmDeleteMedia' => 'SUPPRESSION DÉFINITIVE : ce média WordPress et les fichiers que WordPress lui associe vont être supprimés. Continuer ?',
					'confirmDeleteFamily'=> 'SUPPRESSION DÉFINITIVE : tous les fichiers physiques de cette famille vont être supprimés. Si elle est liée à WordPress, le média correspondant sera également supprimé de la médiathèque. Continuer ?',
				),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'audit-medias' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'dashboard', 'media', 'files', 'woo', 'errors', 'settings' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'dashboard';
		}
		$scan = $this->db->get_latest_scan();
		?>
		<div class="wrap amf-wrap">
            <header class="amf-client-header">
                <div class="amf-brand-heading"><a class="amf-brand-logo" href="https://www.benfou.fr/" target="_blank" rel="noopener noreferrer" aria-label="Benfou - ouvrir le site dans un nouvel onglet"><img src="<?php echo esc_url(AMF_PLUGIN_URL . 'assets/brand/benfou-logo.svg'); ?>" width="132" height="34" alt="Benfou"></a><span class="amf-brand-divider" aria-hidden="true"></span><div><h1>Audit Médias <span class="amf-version">v<?php echo esc_html(AMF_VERSION); ?></span></h1><span class="amf-byline">Voir clair dans vos images.</span></div></div>
                <span class="amf-status-chip <?php echo $this->actions->deletion_enabled() ? 'amf-status-danger' : 'amf-status-safe'; ?>">Suppressions <?php echo $this->actions->deletion_enabled() ? 'activées' : 'désactivées'; ?></span>
            </header>
            <?php if ( $scan && ! AMF_Reference_Parser::scan_is_current( $scan ) ) : ?>
            <div class="notice notice-warning inline amf-parser-update"><p><strong>Un nouvel audit est nécessaire.</strong> <a href="<?php echo esc_url(admin_url('tools.php?page=audit-medias&tab=dashboard')); ?>">Relancer l’analyse</a> pour réactiver les filtres d’absence et les suppressions.</p></div>
            <?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php
				$tabs = array(
					'dashboard' => array( 'Vue d’ensemble', 'dashboard' ),
					'files'     => array( 'Médias', 'format-gallery' ),
					'woo'       => array( 'WooCommerce', 'cart' ),
					'errors'    => array( 'Alertes', 'warning' ),
					'settings'  => array( 'Réglages', 'admin-generic' ),
				);
				if ( ! $this->product_usage->available() ) { unset( $tabs['woo'] ); }
				$active_tab = 'media' === $tab ? 'files' : $tab;
				foreach ( $tabs as $key => $item ) {
					$url = admin_url( 'tools.php?page=audit-medias&tab=' . $key );
					echo '<a class="nav-tab ' . ( $active_tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active_tab === $key ? ' aria-current="page"' : '' ) . '><span class="dashicons dashicons-' . esc_attr( $item[1] ) . '" aria-hidden="true"></span> ' . esc_html( $item[0] ) . '</a>';
				}
				?>
			</nav>
			<div class="amf-tab-content">
				<?php
				if ( ! $scan && ! in_array( $tab, array( 'dashboard', 'settings' ), true ) ) {
					echo '<div class="notice notice-warning inline"><p>Lancez une première analyse depuis le tableau de bord.</p></div>';
				} else {
					switch ( $tab ) {
						case 'media':
							$this->render_media_tab( $scan );
							break;
						case 'files':
							$this->render_files_tab( $scan );
							break;
						case 'woo':
							$this->render_woo_tab( $scan );
							break;
						case 'errors':
							$this->render_errors_tab( $scan );
							break;
						case 'settings':
							$this->render_settings_tab();
							break;
						default:
							$this->render_dashboard( $scan );
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	public function ajax_start_scan() {
		$this->ajax_guard();
		$scan = $this->scanner->start_new_scan();
		wp_send_json_success( $this->scanner->progress_payload( $scan ) );
	}

	public function ajax_resume_scan() {
		$this->ajax_guard();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$result = $this->scanner->resume_scan( $scan_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 404 );
		}
		wp_send_json_success( $this->scanner->progress_payload( $result ) );
	}

	public function ajax_process_batch() {
		$this->ajax_guard();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$result = $this->scanner->process_one_batch( $scan_id );
		if ( is_wp_error( $result ) ) {
			$status = 'amf_scan_busy' === $result->get_error_code() ? 409 : 500;
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), $status );
		}
		wp_send_json_success( $result );
	}

	public function ajax_scan_status() {
		$this->ajax_guard();
		wp_send_json_success( $this->scanner->progress_payload() );
	}

	public function ajax_save_performance_settings() {
		$this->ajax_guard();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$clean = $this->scanner->save_performance_settings( $raw );
		wp_send_json_success(
			array(
				'settings' => $clean,
				'status'   => $this->scanner->progress_payload(),
				'message'  => 'Réglages enregistrés. Ils seront appliqués dès le prochain lot, sans recommencer l’analyse.',
			)
		);
	}

	public function ajax_save_deletion_settings() {
		$this->ajax_guard();
		$enabled = ! empty( $_POST['enabled'] );
		$this->actions->set_deletion_enabled( $enabled );
		wp_send_json_success(
			array(
				'enabled' => $this->actions->deletion_enabled(),
				'message' => $enabled ? 'Actions de suppression activées.' : 'Actions de suppression désactivées.',
			)
		);
	}

    public function ajax_product_index() {
        $this->ajax_guard();
        $restart=isset($_POST['restart']) && '1'===(string)$_POST['restart'];
        $result=$this->product_usage->step($restart);
        if(is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message(),'code'=>$result->get_error_code()),'amf_wc_busy'===$result->get_error_code()?409:500); }
        wp_send_json_success($result);
    }

    public function ajax_family_details() {
        $this->ajax_guard();
        $sid=isset($_POST['scan_id'])?absint($_POST['scan_id']):0;
        $key=isset($_POST['family_key'])&&is_scalar($_POST['family_key'])?sanitize_key(wp_unslash($_POST['family_key'])):'';
        $id=isset($_POST['attachment_id'])?absint($_POST['attachment_id']):0;
        $fid=isset($_POST['file_id'])?absint($_POST['file_id']):0;
        $scan=$this->db->get_scan($sid);
        if(!$scan) { wp_send_json_error(array('message'=>'Audit introuvable.'),404); }
        $where=$this->family_where($sid,$key,$id,$fid);
        $family=$this->wpdb->get_row('SELECT MAX(attachment_id) AS attachment_id, MAX(family_key) AS family_key, MAX(association_confidence) AS confidence, COUNT(DISTINCT attachment_id) AS owners FROM '.$this->db->table('files').' WHERE '.$where,ARRAY_A);
        if(!$family || !$family['family_key']) { wp_send_json_error(array('message'=>'Famille introuvable. Relancez le scan si ces résultats proviennent d\'une ancienne version.'),404); }
        $id=absint($family['attachment_id']); $key=$family['family_key'];
        ob_start();
        $this->render_usage_detail($scan,$id,$key,$fid, $id && ((int)$family['confidence']<100 || (int)$family['owners']>1));
        $html=ob_get_clean();
        wp_send_json_success(array('html'=>$html));
    }

    public function ajax_family_files() {
        $this->ajax_guard();
        $sid=isset($_POST['scan_id'])?absint($_POST['scan_id']):0;
        $key=isset($_POST['family_key'])&&is_scalar($_POST['family_key'])?sanitize_key(wp_unslash($_POST['family_key'])):'';
        $id=isset($_POST['attachment_id'])?absint($_POST['attachment_id']):0;
        $fid=isset($_POST['file_id'])?absint($_POST['file_id']):0;
        $page=max(1,isset($_POST['file_page'])?absint($_POST['file_page']):1);
        if(!$this->db->get_scan($sid)) { wp_send_json_error(array('message'=>'Audit introuvable.'),404); }
        $where=$this->family_where($sid,$key,$id,$fid); $table=$this->db->table('files');
        $total=(int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $page=min($page,max(1,(int)ceil($total/100)));
        $rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY current_meta DESC, classification, rel_path LIMIT %d OFFSET %d",100,($page-1)*100),ARRAY_A);
        if($this->wpdb->last_error) { wp_send_json_error(array('message'=>'Les fichiers ne peuvent pas être lus.'),500); }
        ob_start();
        $this->render_family_files_table($rows);
        echo '<div class="amf-file-pagination"><span>'.esc_html($total).' fichiers - page '.esc_html($page).' / '.esc_html(max(1,(int)ceil($total/100))).'</span>';
        if($page>1) { echo '<button type="button" class="button amf-file-page" data-file-page="'.esc_attr($page-1).'">Precedent</button>'; }
        if($page*100<$total) { echo '<button type="button" class="button amf-file-page" data-file-page="'.esc_attr($page+1).'">Suivant</button>'; }
        echo '</div>';
        wp_send_json_success(array('html'=>ob_get_clean()));
    }

	public function ajax_delete_media() {
		$this->ajax_guard();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$result = $this->actions->delete_attachment( $scan_id, $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_delete_family() {
		$this->ajax_guard();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$family_key = isset( $_POST['family_key'] ) ? sanitize_text_field( wp_unslash( $_POST['family_key'] ) ) : '';
		$force = isset( $_POST['force'] ) && '1' === (string) wp_unslash( $_POST['force'] );
		$confirmation = isset( $_POST['confirmation'] ) && is_scalar( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
		$result = $this->actions->delete_family( $scan_id, $family_key, $force, $confirmation );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_refresh_woo_image_usage() {
		$this->ajax_guard();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$result = $this->actions->refresh_woo_image_usage( $scan_id, $attachment_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}
		wp_send_json_success( $result );
	}

	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
		}
		check_ajax_referer( 'amf_scan_nonce', 'nonce' );
	}

    private function render_dashboard( $scan ) {
        $scan_id = $scan ? (int) $scan['id'] : 0;
        $ready = $scan && $this->usage_results_ready($scan);
        ?>
        <section class="amf-scan-panel">
            <div class="amf-panel-heading"><h2>Analyse du site</h2><span id="amf-scan-state" class="amf-scan-live" aria-live="polite"><?php echo $ready ? 'Terminée' : ($scan ? 'À reprendre' : 'Non démarrée'); ?></span></div>
            <div id="amf-progress" class="amf-progress" data-scan-id="<?php echo esc_attr($scan_id); ?>">
                <div class="amf-progress-head"><strong id="amf-phase-label"><?php echo $ready ? 'Analyse terminée' : 'Analyse des médias'; ?></strong><span id="amf-progress-numbers"></span></div>
                <div id="amf-progress-track" class="amf-progress-track" role="progressbar" aria-label="Progression de l’étape" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="amf-progress-bar" class="amf-progress-bar"></div></div>
                <p id="amf-progress-message" class="description"><?php echo $ready ? 'Consultez les médias et leurs utilisations.' : 'L’analyse ne supprime aucun fichier. Gardez cet onglet ouvert pour la faire avancer.'; ?></p>
            </div>
            <div class="amf-actions">
                <?php if ($ready) : ?><a class="button button-primary" href="<?php echo esc_url(admin_url('tools.php?page=audit-medias&tab=files')); ?>">Parcourir les médias</a><?php endif; ?>
                <button type="button" class="button <?php echo $ready ? '' : 'button-primary'; ?>" id="amf-start-scan"><?php echo $scan ? 'Relancer l’analyse' : 'Lancer l’analyse'; ?></button>
                <?php if ($scan && 'complete' !== $scan['status']) : ?><button type="button" class="button" id="amf-resume-scan">Reprendre</button><button type="button" class="button" id="amf-pause-scan" disabled>Pause</button><?php endif; ?>
            </div>
            <?php if (!$ready) : ?><p class="description">La progression est conservée si vous fermez la page.</p><?php endif; ?>
        </section>
        <?php
        if ($scan_id) {
            $stats = $this->dashboard_stats($scan_id);
            echo '<div class="amf-cards amf-client-cards">';
            $this->stat_card('Médias WordPress',number_format_i18n($stats['media']));
            $this->stat_card('Fichiers analysés',$this->files_results_ready($scan) ? number_format_i18n($stats['physical_files']) : 'En attente');
            $this->stat_card('Sans référence détectée',$ready ? $stats['no_usage'] : 'En attente');
            echo '</div>';
            if ($ready) {
                echo '<p class="amf-safety-note">Vérifiez les utilisations et sauvegardez le site avant toute suppression.</p>';
                echo '<p><a href="'.esc_url(admin_url('tools.php?page=audit-medias&tab=errors')).'">Consulter les alertes de l’audit</a></p>';
            }
        }
    }

    private function render_settings_tab() {
        $performance = $this->scanner->get_performance_settings();
        $deletion_enabled = $this->actions->deletion_enabled();
        ?>
        <h2>Réglages</h2>
        <section class="amf-info-box amf-danger-settings">
            <h3>Suppression manuelle</h3>
            <p>Les suppressions sont définitives. Conservez une sauvegarde et vérifiez les utilisations avant de confirmer.</p>
            <label class="amf-toggle-line"><input type="checkbox" id="amf-deletion-enabled" <?php checked($deletion_enabled); ?>> Autoriser les boutons de suppression</label>
            <p><button type="button" class="button" id="amf-save-deletion">Enregistrer</button> <span id="amf-deletion-message" role="status"></span></p>
        </section>
        <section class="amf-info-box">
            <h3>Vitesse de l’analyse</h3>
            <p>Gardez les réglages actuels si l’analyse fonctionne correctement.</p>
            <div class="amf-presets"><button type="button" class="button amf-preset" data-preset="prudent">Prudent</button><button type="button" class="button amf-preset" data-preset="balanced">Équilibré</button><button type="button" class="button amf-preset" data-preset="fast">Rapide</button></div>
            <details class="amf-disclosure"><summary>Réglages avancés des lots</summary><p>Des lots plus grands sollicitent davantage l’hébergement. Le temps maximal limite chaque requête, pas la durée totale de l’audit.</p>
					<div class="amf-settings-grid">
						<?php $this->performance_field( 'media_batch', 'Médias WordPress / lot', $performance['media_batch'], '1–100.' ); ?>
						<?php $this->performance_field( 'posts_batch', 'Contenus / Gutenberg / lot', $performance['posts_batch'], '1–250.' ); ?>
						<?php $this->performance_field( 'postmeta_batch', 'Métadonnées / constructeurs / extensions / lot', $performance['postmeta_batch'], '10–1000. Souvent la phase la plus volumineuse.' ); ?>
						<?php $this->performance_field( 'termmeta_batch', 'Taxonomies / catégories / marques / lot', $performance['termmeta_batch'], '10–1000.' ); ?>
						<?php $this->performance_field( 'options_batch', 'Options WordPress / lot', $performance['options_batch'], '1–250.' ); ?>
						<?php $this->performance_field( 'files_batch', 'Fichiers physiques / lot', $performance['files_batch'], '25–1000.' ); ?>
						<?php $this->performance_field( 'woo_batch', 'Produits WooCommerce / lot', $performance['woo_batch'], '1–50. Ignoré si WooCommerce est absent.' ); ?>
						<?php $this->performance_field( 'request_delay_ms', 'Délai entre deux requêtes (ms)', $performance['request_delay_ms'], '0–3000 ms.' ); ?>
						<?php $this->performance_field( 'time_budget_seconds', 'Temps maximal par lot (s)', $performance['time_budget_seconds'], '3–18 s.' ); ?>
					</div>
            </details>
            <p><button type="button" class="button button-primary" id="amf-save-performance">Enregistrer la cadence</button> <span id="amf-settings-message" role="status"></span></p>
        </section>
        <?php AMF_Updates::render_settings(); ?>
        <?php $this->render_technical_settings(); ?>
        <p class="description">Développé par <a href="https://www.benfou.fr/" target="_blank" rel="noopener noreferrer">Benfou</a> · <a href="https://github.com/benoitfouc/audit-medias" target="_blank" rel="noopener noreferrer">Documentation</a></p>
        <?php
    }

    private function render_technical_settings() {
        $scan = $this->db->get_latest_scan();
        $product = $this->product_usage->status();
        $diagnostic = array('Extension' => AMF_VERSION, 'WordPress' => get_bloginfo('version'), 'PHP' => PHP_VERSION);
        if ($scan) {
            foreach (array('id','status','phase','started_at','finished_at') as $key) {
                $diagnostic['Audit / '.$key] = isset($scan[$key]) ? (string)$scan[$key] : '';
            }
            $diagnostic['Moteur de références'] = AMF_Reference_Parser::scan_is_current($scan) ? 'À jour' : 'Nouvel audit requis';
        }
        $diagnostic['Images produits / état'] = isset($product['status']) ? $product['status'] : '';
        $diagnostic['Images produits / vérification'] = isset($product['checked_at']) ? $product['checked_at'] : '';
        ?>
        <details id="amf-technical" class="amf-disclosure amf-technical">
            <summary>Technique / diagnostic</summary>
            <p>Informations de maintenance. Aucun journal supplémentaire ni collecte de données n’est activé.</p>
            <dl class="amf-diagnostic-list"><?php foreach ($diagnostic as $label => $value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value ?: '—'); ?></dd><?php endforeach; ?></dl>
            <?php if ($scan && !empty($scan['message'])) : ?><p><strong>Dernier message de l’audit :</strong> <?php echo esc_html($scan['message']); ?></p><?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(admin_url('tools.php?page=audit-medias&tab=media')); ?>">Ouvrir l’inventaire WordPress avancé</a></p>
            <p>Cette vue conserve les médias enregistrés en base, y compris ceux dont tous les fichiers ont disparu. L’onglet Médias regroupe les familles de fichiers physiques.</p>
            <?php if ($scan) : $stats = $this->dashboard_stats((int)$scan['id']); ?>
            <details class="amf-disclosure"><summary>Statistiques détaillées</summary><dl class="amf-diagnostic-list"><?php foreach ($stats as $label => $value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html(is_scalar($value) ? (string)$value : ''); ?></dd><?php endforeach; ?></dl></details>
            <?php endif; ?>
            <details class="amf-disclosure"><summary>Périmètre de détection et limites</summary>
                <p>Contenus WordPress, Gutenberg, shortcodes, métadonnées, Elementor, WPBakery, taxonomies, options et URL identifiables. Une référence sur un dérivé protège la famille entière.</p>
                <p>Les tailles déclarées dans les métadonnées et les anciens rattachements ne prouvent pas une utilisation actuelle. Les fichiers externes, tables propriétaires, contenus dynamiques et certains CDN peuvent échapper à l’analyse.</p>
                <p>L’actualisation des images produits relit les images principales, galeries et variations, mais ne remplace pas l’audit complet. Les produits en corbeille sont exclus ; les brouillons restent identifiés.</p>
                <p>Les mises à jour interrogent les releases publiques GitHub, sans jeton. Le cache est conservé jusqu’à 6 heures (30 minutes après une erreur). Le bouton de recherche ci-dessus le renouvelle, sans installer de version.</p>
            </details>
        </details>
        <?php
    }

    private function render_media_tab( $scan ) {
        $id = isset($_GET['media_id']) ? absint($_GET['media_id']) : 0;
        if ($id) { $this->render_media_detail($scan,$id); return; }
        $this->render_usage_list($scan,'media');
    }

    private function render_files_tab( $scan ) {
        $this->render_usage_list($scan,'files');
    }

    private function usage_filters() {
        $out = array();
        foreach (array('s','state','assoc','wc_usage','other_usage','orderby','order') as $key) {
            $raw = isset($_GET[$key]) && is_scalar($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
            $out[$key] = 's'===$key ? sanitize_text_field($raw) : sanitize_key($raw);
        }
        $out['per_page']=$this->get_per_page(50);
        $out['paged']=max(1,isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        return $out;
    }

    private function product_index_panel( $scan ) {
        $s = $this->product_usage->status();
        if (!$s['available']) { return; }
        ?>
        <div class="amf-product-index amf-product-index-compact" data-auto="<?php echo esc_attr(in_array($s['status'],array('idle','stale','running'),true) ? '1' : '0'); ?>">
            <span class="amf-product-index-status"><?php echo $s['ready'] ? 'Images produits à jour' : 'Images produits à vérifier'; ?></span>
            <div class="amf-product-index-controls"><button type="button" class="button-link amf-refresh-product-index">Actualiser</button><button type="button" class="button amf-pause-product-index" hidden>Pause</button></div>
            <div class="amf-product-index-progress" hidden><progress max="100" value="0"></progress><span aria-live="polite"></span></div>
            <p class="amf-product-index-message" role="status"><?php echo esc_html(!$s['ready'] && 'error' === $s['status'] ? $s['message'] : ''); ?></p>
        </div>
        <?php
    }

    private function render_bulk_actions( $scan_id, $position ) {
        $id = 'amf-family-bulk-action-' . $position;
        ?>
        <div class="amf-bulk-actions" data-scan-id="<?php echo esc_attr($scan_id); ?>">
            <label for="<?php echo esc_attr($id); ?>" class="screen-reader-text">Action sur les familles sélectionnées</label>
            <select id="<?php echo esc_attr($id); ?>" class="amf-family-bulk-action" <?php disabled(!$this->actions->deletion_enabled()); ?>><option value="">Actions groupées</option><option value="delete">Supprimer la sélection</option></select>
            <button type="button" class="button amf-apply-family-bulk" disabled>Appliquer</button>
            <span class="amf-bulk-selection-count">0 famille sélectionnée</span><span class="amf-bulk-progress" role="status"></span>
            <?php if (!$this->actions->deletion_enabled() && 'top' === $position) : ?><a class="amf-text-muted" href="<?php echo esc_url(admin_url('tools.php?page=audit-medias&tab=settings')); ?>">Activer la suppression dans les réglages</a><?php endif; ?>
        </div>
        <?php
    }

    private function render_usage_list( $scan, $mode ) {
        $sid=(int)$scan['id']; $is_files='files'===$mode;
        $usage_ready=$this->usage_results_ready($scan); $files_ready=$this->files_results_ready($scan);
        $filters=$this->usage_filters();
        $result=$this->usage_view->list_data($scan,$mode,$filters,$usage_ready);
        $has_woo = $this->product_usage->available();
        echo '<div class="amf-section-head amf-list-heading"><div><h2>' . ($is_files ? 'Médias' : 'Inventaire WordPress avancé') . '</h2><p class="description">' . ($is_files ? 'Une ligne par image, avec ses variantes.' : 'Médias enregistrés dans WordPress, même sans fichier physique.') . '</p></div>';
        $this->product_index_panel($scan);
        echo '</div>';
        if (!$is_files) { echo '<p><a href="'.esc_url(admin_url('tools.php?page=audit-medias&tab=files')).'">← Retour aux médias</a></p>'; }
        if (is_wp_error($result)) { echo '<div class="notice notice-error inline"><p>' . esc_html($result->get_error_message()) . '</p></div>'; return; }
        if (AMF_Reference_Parser::scan_is_current($scan) && (!$usage_ready || !$files_ready)) { echo '<div class="notice notice-info inline"><p>Audit principal en cours : les références et fichiers restent provisoires.</p></div>'; }
        $wc=$this->product_usage->status();
        if ((!$wc['ready'] && $filters['wc_usage']) || (!$usage_ready && $filters['other_usage'])) {
            echo '<div class="notice notice-warning inline"><p>Ce filtre attend une vérification terminée. Aucun résultat négatif n\'est affiché entre-temps.</p></div>';
        }
        $rows=$result['rows']; $cols=($is_files ? 8 : 6) - ($has_woo ? 0 : 1);
        $ids=array_column($rows,'attachment_id'); $keys=array_column($rows,'group_key');
        $product_refs=$this->product_usage->references($ids);
        $other_refs=$this->usage_view->other_references($sid,$ids,$keys);
        $selects=array();
        if ($wc['available']) {
            $selects['wc_usage']=array(''=>'Toutes les images produits','absent'=>'Absentes des images produits','used'=>'Affectées à un produit','main'=>'Images principales','gallery'=>'Galeries','variation'=>'Images des variations');
        }
        $selects['other_usage']=array(''=>($has_woo ? 'Références hors images produits' : 'Toutes les références'),'none'=>'Sans autre référence','found'=>'Autres références détectées');
        if ($is_files) { $selects['assoc']=array(''=>'Tous les médias / familles','unassociated'=>'Sans média WordPress identifié','probable'=>'Association physique à confirmer','review'=>'Sans référence détectée','confirmed'=>'Média WordPress identifié','direct_used'=>'Sans média, avec référence par URL'); }
        else { $selects['state']=array(''=>'Tous les fichiers','missing'=>'Fichier principal absent','no_usage'=>'Sans référence détectée','used'=>'Avec une référence confirmée','probable'=>'Références à confirmer'); }
        $this->render_filter_form($mode,$selects,$filters['s'],$result['total'],$filters['per_page']);
        if ($is_files) { $this->render_bulk_actions($sid,'top'); }
        ?>
        <p class="amf-safety-note">Sans référence détectée ne signifie pas inutilisé. Vérifiez avant de supprimer.</p>
        <div class="amf-table-wrap" tabindex="0" role="region" aria-label="Tableau des médias avec défilement horizontal"><table class="widefat striped amf-table amf-usage-table <?php echo $is_files?'amf-family-table':''; ?>"><thead><tr>
            <?php if ($is_files) : ?><th class="check-column amf-col-checkbox"><input type="checkbox" class="amf-family-select-all" aria-label="Sélectionner les familles de cette page" <?php disabled(!$this->actions->deletion_enabled()); ?>></th><th class="amf-col-toggle"><span class="screen-reader-text">Détails</span></th><?php endif; ?>
            <?php echo $this->files_sort_header('Média','media',$filters['orderby'],$filters['order'],'asc','amf-col-media'); ?>
            <?php if ($has_woo) { echo $this->files_sort_header('Images produits','wc',$filters['orderby'],$filters['order'],'desc','amf-col-usage'); } ?>
            <?php echo $this->files_sort_header($has_woo ? 'Autres utilisations' : 'Utilisations','references',$filters['orderby'],$filters['order'],'desc','amf-col-usage'); ?>
            <?php echo $this->files_sort_header('Fichiers','files',$filters['orderby'],$filters['order'],'desc','amf-col-count'); ?>
            <?php echo $this->files_sort_header('Taille','size',$filters['orderby'],$filters['order'],'desc','amf-col-size'); ?>
            <th class="amf-col-actions">Actions</th>
        </tr></thead><tbody>
        <?php if (!$rows) : ?><tr><td colspan="<?php echo esc_attr($cols); ?>">Aucun résultat pour ces filtres.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row) :
            $id=absint($row['attachment_id']); $key=$row['group_key'];
            $label=$row['filename'] ?: ($row['family_stem'] ?: wp_basename($row['sample_rel_path']));
            $file_url=$this->attachment_or_file_url($id,$row['rel_path']);
            $uncertain=($id && (int)$row['max_confidence']<100) || (int)$row['ambiguous_count']>0 || (int)$row['owner_count']>1;
            $can_delete=$is_files && $this->family_can_delete($scan,$row);
            $risks=max(0,(int)$row['wc_count'])+max(0,(int)$row['other_count']);
            $data=' data-scan-id="'.esc_attr($sid).'" data-family-key="'.esc_attr($row['family_key']).'" data-file-count="'.esc_attr($row['file_count']).'" data-attachment-id="'.esc_attr($id).'" data-direct-refs="'.esc_attr($row['direct_usage_count']).'" data-ambiguous="'.esc_attr($row['ambiguous_count']).'" data-usage-count="'.esc_attr($risks).'"';
            $detail_url=admin_url('tools.php?page=audit-medias&tab=media&media_id='.$id);
        ?>
            <tr class="<?php echo $is_files?'amf-family-summary':'amf-media-summary'; ?>" data-family-row="<?php echo esc_attr($key); ?>">
                <?php if ($is_files) : ?>
                <td class="check-column amf-col-checkbox"><input type="checkbox" class="amf-family-checkbox" value="<?php echo esc_attr($row['family_key']); ?>" <?php echo $data; ?> data-total-bytes="<?php echo esc_attr($row['total_bytes']); ?>" aria-label="Sélectionner <?php echo esc_attr($label); ?>" <?php disabled(!$can_delete); ?> title="<?php echo esc_attr($can_delete?'':$this->family_delete_block_reason($scan,$row)); ?>"></td>
                <td><button type="button" class="button-link amf-family-toggle" aria-expanded="false" title="Voir les utilisations et les détails">&#9654;</button></td>
                <?php endif; ?>
                <td><?php echo $this->media_cell_html($id,$row['rel_path'],$label,''); ?>
                    <?php if ($id && !$row['file_exists']) : ?><span class="amf-badge amf-critical">Fichier principal absent au dernier audit</span><?php endif; ?>
                </td>
                <?php if ($has_woo) : ?><td><?php echo $this->usage_view->product_html(is_wp_error($product_refs)?$product_refs:(isset($product_refs[$id])?$product_refs[$id]:array()),$id,true,$uncertain); ?></td><?php endif; ?>
                <td><?php echo $this->usage_view->other_html(is_wp_error($other_refs)?$other_refs:(isset($other_refs[$key])?$other_refs[$key]:array()),$usage_ready,(int)$row['other_count'],true,(int)$row['direct_usage_count']); ?></td>
                <td><?php if ($files_ready) : ?><strong><?php echo esc_html(number_format_i18n($row['file_count'])); ?></strong><?php else : ?>En attente<?php endif; ?></td>
                <td class="amf-no-wrap"><?php echo $files_ready?esc_html(AMF_Utils::human_bytes($row['total_bytes'])):'En attente'; ?></td>
                <td class="amf-actions-cell">
                    <?php if ($file_url) : ?><a href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer">Voir le fichier &#8599;</a><?php endif; ?>
                    <?php if ($is_files) : ?><button type="button" class="button-link amf-family-open">Utilisations</button><?php else : ?><a href="<?php echo esc_url($detail_url); ?>">Détails / utilisations</a><a href="<?php echo esc_url(admin_url('tools.php?page=audit-medias&tab=files&s='.$id)); ?>">Gérer la famille</a><?php endif; ?>
                    <?php if ($can_delete) : ?><button type="button" class="button-link-delete amf-delete-family" <?php echo $data; ?>>Supprimer la famille</button><?php endif; ?>
                </td>
            </tr>
            <?php if ($is_files) : ?><tr class="amf-family-details-row" data-family-details="<?php echo esc_attr($key); ?>" hidden><td colspan="<?php echo esc_attr($cols); ?>"><div class="amf-family-details" data-loaded="0" data-scan-id="<?php echo esc_attr($sid); ?>" data-family-key="<?php echo esc_attr($row['family_key']); ?>" data-attachment-id="<?php echo esc_attr($id); ?>" data-file-id="<?php echo esc_attr($row['sample_file_id']); ?>">Chargement des utilisations...</div></td></tr><?php endif; ?>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($is_files) { $this->render_bulk_actions($sid,'bottom'); }
        $this->pagination($result['total'],$filters['per_page'],$result['paged']);
    }

    private function render_media_detail( $scan, $attachment_id ) {
        $sid=(int)$scan['id'];
        $media=$this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM '.$this->db->table('media').' WHERE scan_id=%d AND attachment_id=%d',$sid,$attachment_id),ARRAY_A);
        if (!$media) { echo '<p>Média absent de cet audit.</p>'; return; }
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=audit-medias&tab=media')).'">&#8592; Retour aux médias</a></p>';
        echo '<h2>'.esc_html($media['filename'] ?: $media['title']).'</h2>';
        $this->product_index_panel($scan);
        $url=$this->attachment_or_file_url($attachment_id,$media['rel_path']);
        echo '<div class="amf-detail-summary">'.$this->media_preview_html($attachment_id,$media['rel_path'],140).'<div><p><strong>Média #'.esc_html($attachment_id).'</strong><br>'.esc_html($media['width'].' x '.$media['height']).' px - '.esc_html(AMF_Utils::human_bytes($media['total_bytes'])).'</p>';
        echo '<p>'.$this->action_links(get_edit_post_link($attachment_id,'raw'),'',$url).'</p><p><a href="'.esc_url(admin_url('tools.php?page=audit-medias&tab=files&s='.$attachment_id)).'">Gérer / supprimer la famille complète</a></p></div></div>';
        $this->render_usage_detail($scan,$attachment_id,AMF_Utils::family_key_for_attachment($attachment_id));
    }

    private function render_usage_detail( $scan, $id, $family_key, $file_id = 0, $ambiguous = false ) {
        $sid=(int)$scan['id']; $refs=$this->product_usage->references(array($id));
        $other=$this->usage_view->other_references($sid,array($id),array($family_key));
        $where=$this->family_where($sid,$family_key,$id,$file_id);
        $direct_refs=(int)$this->wpdb->get_var('SELECT COALESCE(SUM(direct_usage_count),0) FROM '.$this->db->table('files').' WHERE '.$where);
        echo '<div class="amf-usage-detail-grid"><section><h3>Images produits</h3>'.$this->usage_view->product_html(is_wp_error($refs)?$refs:(isset($refs[$id])?$refs[$id]:array()),$id,false,$ambiguous).'</section>';
        echo '<section><h3>Autres références sur le site</h3><p class="description">Dernier audit : '.esc_html($scan['finished_at'] ?: $scan['started_at']).'</p>'.$this->usage_view->other_html(is_wp_error($other)?$other:(isset($other[$family_key])?$other[$family_key]:array()),$this->usage_results_ready($scan),0,false,$direct_refs).'</section></div>';
        echo $this->usage_view->history_html($id);
        $n=(int)$this->wpdb->get_var('SELECT COUNT(*) FROM '.$this->db->table('files').' WHERE '.$where);
        echo '<details class="amf-file-accordion"><summary>Voir les '.esc_html(number_format_i18n($n)).' fichiers de cette famille</summary><p class="description">Originaux, miniatures et conversions. Une référence sur un fichier concerne toute la famille.</p>';
        echo '<div class="amf-files-lazy" data-loaded="0" data-scan-id="'.esc_attr($sid).'" data-family-key="'.esc_attr($family_key).'" data-attachment-id="'.esc_attr($id).'" data-file-id="'.esc_attr($file_id).'">Chargement...</div></details>';
    }

    private function family_where( $scan_id, $key, $attachment_id, $file_id ) {
        if (preg_match('/^[a-f0-9]{32}$/D',(string)$key)) {
            return $this->wpdb->prepare('scan_id=%d AND family_key=%s AND physical=1',$scan_id,$key);
        }
        if ($attachment_id) { return $this->wpdb->prepare('scan_id=%d AND attachment_id=%d AND physical=1',$scan_id,$attachment_id); }
        return $this->wpdb->prepare('scan_id=%d AND id=%d AND physical=1',$scan_id,$file_id);
    }

	private function render_woo_tab( $scan ) {
		$scan_id = (int) $scan['id'];
		$woo_ready = $this->woo_results_ready( $scan );
		$severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = $this->get_per_page( 50 );
		$where = array( 'scan_id = %d', "object_type IN ('product','variation')" );
		$params = array( $scan_id );
		if ( in_array( $severity, array( 'critical', 'regenerable', 'manual' ), true ) ) { $where[] = 'severity = %s'; $params[] = $severity; }
		if ( in_array( $type, array( 'product', 'variation' ), true ) ) { $where[] = 'object_type = %s'; $params[] = $type; }
		if ( $code ) { $where[] = 'code = %s'; $params[] = $code; }
		if ( 'gallery' === $role ) { $where[] = 'image_role = %s'; $params[] = 'Galerie'; }
		$table = $this->db->table( 'issues' );
		$where_sql = implode( ' AND ', $where );
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY FIELD(severity,'critical','manual','regenerable','warning'), id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="amf-section-head"><div><h2>Qualité des images WooCommerce</h2><p class="description">Images manquantes ou à corriger, classées par priorité.</p></div></div>
		<?php if ( ! class_exists( 'WooCommerce' ) ) : ?><div class="notice notice-info inline"><p>WooCommerce n’est pas actif sur ce site : cet onglet n’a rien à auditer.</p></div><?php endif; ?>
		<?php if ( ! $woo_ready ) : ?><div class="notice notice-info inline"><p><strong>Audit WooCommerce pas encore terminé.</strong> Les résultats ne sont pas encore exhaustifs.</p></div><?php endif; ?>
		<form method="get" class="amf-filters"><input type="hidden" name="page" value="audit-medias"><input type="hidden" name="tab" value="woo"><input type="hidden" name="per_page" value="<?php echo esc_attr( $per_page ); ?>">
		<select name="severity"><option value="">Toutes les erreurs</option><?php foreach ( array( 'critical' => 'Critiques', 'manual' => 'Intervention manuelle', 'regenerable' => 'Régénérables' ) as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $severity, $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?></select>
		<select name="type"><option value="">Produits + variations</option><option value="product" <?php selected( $type, 'product' ); ?>>Produits</option><option value="variation" <?php selected( $type, 'variation' ); ?>>Variations</option></select>
		<select name="role"><option value="">Toutes les images</option><option value="gallery" <?php selected( $role, 'gallery' ); ?>>Galeries</option></select>
		<select name="code"><option value="">Tous les problèmes</option><option value="original_missing" <?php selected( $code, 'original_missing' ); ?>>Original absent</option><option value="attachment_missing" <?php selected( $code, 'attachment_missing' ); ?>>Attachment supprimé</option><option value="woo_subsize_missing" <?php selected( $code, 'woo_subsize_missing' ); ?>>Taille WooCommerce absente</option><option value="source_too_small" <?php selected( $code, 'source_too_small' ); ?>>Image trop petite</option><option value="image_unreadable" <?php selected( $code, 'image_unreadable' ); ?>>Image illisible</option></select>
		<button class="button">Filtrer</button><span class="amf-filter-count"><?php echo esc_html( number_format_i18n( $total ) ); ?> résultat(s)</span></form>
		<div class="amf-table-wrap"><table class="widefat striped amf-table"><thead><tr><th class="amf-col-media">Média</th><th>Produit</th><th>Type</th><th>État</th><th>Problème</th><th>Actions</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6">Aucun problème correspondant détecté.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) :
			$file_url = $row['file_rel_path'] ? AMF_Utils::file_url( $row['file_rel_path'] ) : $this->attachment_or_file_url( (int) $row['attachment_id'] );
			?>
			<tr><td><?php echo $this->media_cell_html( (int) $row['attachment_id'], $row['file_rel_path'], $row['attachment_id'] ? 'Média #' . $row['attachment_id'] : 'Image non disponible', $row['image_role'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php $source = $this->product_usage->source_link( (int) $row['object_id'] ); ?><strong><?php if ($source['url']) : ?><a href="<?php echo esc_url($source['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['object_title']); ?> &#8599;</a><?php else : ?><?php echo esc_html($row['object_title']); ?><?php endif; ?></strong><br><small>#<?php echo esc_html( $row['object_id'] ); ?></small></td><td><?php echo 'variation' === $row['object_type'] ? 'Variation' : 'Produit'; ?><br><small><?php echo esc_html( $row['image_role'] ); ?></small></td><td><?php echo wp_kses_post( $this->severity_badge( $row['severity'] ) ); ?></td><td><?php echo esc_html( $row['message'] ); ?><?php if ( $row['details'] ) : ?><br><small><?php echo esc_html( $row['details'] ); ?></small><?php endif; ?></td><td><?php echo $this->action_links( $row['edit_url'], $row['view_url'], $file_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php $this->pagination( $total, $per_page, $paged );
	}

	private function render_errors_tab( $scan ) {
		$scan_id = (int) $scan['id'];
		$severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = $this->get_per_page( 75 );
		$table = $this->db->table( 'issues' );
		$where = array( 'scan_id = %d' );
		$params = array( $scan_id );
		if ( in_array( $severity, array( 'critical', 'manual', 'regenerable', 'warning' ), true ) ) { $where[] = 'severity = %s'; $params[] = $severity; }
		$where_sql = implode( ' AND ', $where );
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY FIELD(severity,'critical','manual','regenerable','warning'), id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="amf-section-head"><div><h2>Erreurs et avertissements</h2><p class="description">Les éléments les plus critiques sont toujours placés en premier.</p></div></div>
		<?php $this->render_filter_form( 'errors', array( 'severity' => array( '' => 'Tous les états', 'critical' => 'Critiques', 'manual' => 'Intervention manuelle', 'regenerable' => 'Régénérables', 'warning' => 'Avertissements' ) ), false, $total, $per_page ); ?>
		<div class="amf-table-wrap"><table class="widefat striped amf-table"><thead><tr><th class="amf-col-media">Média</th><th>État</th><th>Objet</th><th>Code</th><th>Détail</th><th>Actions</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6">Aucun avertissement enregistré.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) :
			$rel = $row['file_rel_path'];
			if ( ! $rel && AMF_Utils::looks_like_image_rel_path( $row['details'] ) ) { $rel = $row['details']; }
			$file_url = $rel ? AMF_Utils::file_url( $rel ) : $this->attachment_or_file_url( (int) $row['attachment_id'] );
			?>
			<tr><td><?php echo $this->media_cell_html( (int) $row['attachment_id'], $rel, $row['attachment_id'] ? 'Média #' . $row['attachment_id'] : '—', $row['image_role'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo wp_kses_post( $this->severity_badge( $row['severity'] ) ); ?></td><td><?php echo esc_html( $row['object_type'] . ( $row['object_id'] ? ' #' . $row['object_id'] : '' ) ); ?><br><small><?php echo esc_html( $row['object_title'] ); ?></small></td><td><code><?php echo esc_html( $row['code'] ); ?></code></td><td><?php echo esc_html( $row['message'] ); ?><?php if ( $row['details'] ) : ?><br><small><?php echo esc_html( $row['details'] ); ?></small><?php endif; ?></td><td><?php echo $this->action_links( $row['edit_url'], $row['view_url'], $file_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php $this->pagination( $total, $per_page, $paged );
	}

	private function phase_rank( $phase ) {
		$order = array( 'media' => 10, 'usage_posts' => 20, 'usage_postmeta' => 30, 'usage_termmeta' => 40, 'usage_options' => 50, 'files' => 60, 'woo' => 70, 'finalize' => 80, 'complete' => 90 );
		return isset( $order[ $phase ] ) ? $order[ $phase ] : 0;
	}

	private function usage_results_ready( $scan ) { return AMF_Reference_Parser::scan_is_current( $scan ) && 'complete' === $scan['status']; }
	private function files_results_ready( $scan ) { return $scan && $this->phase_rank( $scan['phase'] ) >= $this->phase_rank( 'woo' ); }
	private function woo_results_ready( $scan ) { return $scan && $this->phase_rank( $scan['phase'] ) >= $this->phase_rank( 'finalize' ); }

	private function dashboard_stats( $scan_id ) {
		$media = $this->db->table( 'media' );
		$files = $this->db->table( 'files' );
		$issues = $this->db->table( 'issues' );
		$stats = array();
		$stats['media'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$media} WHERE scan_id = %d", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats['physical_files'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE scan_id = %d AND physical = 1", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats['unassociated'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE scan_id = %d AND physical = 1 AND attachment_id IS NULL", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats['unassociated_used'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE scan_id = %d AND physical = 1 AND attachment_id IS NULL AND direct_usage_count > 0", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wc_state=$this->product_usage->status();
        $wc_sql=$this->product_usage->aggregate_sql();
        $other_sql=$this->usage_view->other_aggregate_sql($scan_id);
        $combined=$this->wpdb->get_row($this->wpdb->prepare(
            "SELECT SUM(COALESCE(w.wc_count,0)>0 OR COALESCE(o.other_confirmed,0)>0 OR COALESCE(d.direct_hits,0)>0) AS used,
             SUM(COALESCE(w.wc_count,0)=0 AND COALESCE(o.other_count,0)=0 AND COALESCE(d.direct_hits,0)=0) AS no_usage,
             SUM(COALESCE(w.wc_count,0)=0) AS wc_absent FROM {$media} m
             LEFT JOIN ({$wc_sql}) w ON w.attachment_id=m.attachment_id
             LEFT JOIN ({$other_sql}) o ON o.group_key=MD5(CONCAT('attachment:',m.attachment_id))
             LEFT JOIN (
                 SELECT attachment_id, SUM(direct_usage_count) AS direct_hits FROM {$files}
                 WHERE scan_id=%d AND physical=1 AND attachment_id IS NOT NULL GROUP BY attachment_id
             ) d ON d.attachment_id=m.attachment_id
             WHERE m.scan_id=%d",$scan_id,$scan_id
        ),ARRAY_A);
        $valid=(!$wc_state['available'] || $wc_state['ready']) && !$this->wpdb->last_error;
        $stats['used']=$valid ? (int)$combined['used'] : 'À vérifier';
        $stats['no_usage']=$valid ? (int)$combined['no_usage'] : 'À vérifier';
        $stats['wc_absent']=$valid ? (int)$combined['wc_absent'] : 'À vérifier';
		$stats['avg_files'] = $stats['media'] ? $stats['physical_files'] / $stats['media'] : 0;
		$group_expr = "CASE WHEN family_key <> '' THEN family_key WHEN attachment_id IS NOT NULL THEN MD5(CONCAT('attachment:', attachment_id)) ELSE MD5(CONCAT('legacy-file:', id)) END";
		$stats['review_families'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM (SELECT {$group_expr} AS g, MAX(attachment_id) AS aid, SUM(direct_usage_count) AS uses FROM {$files} WHERE scan_id = %d AND physical = 1 GROUP BY g HAVING MAX(attachment_id) IS NULL AND SUM(direct_usage_count) = 0) x", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 'critical', 'regenerable', 'manual' ) as $severity ) {
			$stats[ $severity ] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$issues} WHERE scan_id = %d AND severity = %s AND object_type IN ('product','variation')", $scan_id, $severity ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $stats;
	}

	private function performance_field( $key, $label, $value, $help = '' ) {
		?><label class="amf-setting-field"><span><?php echo esc_html( $label ); ?></span><input type="number" class="small-text amf-performance-input" data-key="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" min="0" step="1"><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></label><?php
	}

	private function stat_card( $label, $value ) { echo '<div class="amf-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>'; }

	private function media_state_badge( $row, $scan = null ) {
		if ( empty( $row['file_exists'] ) ) return '<span class="amf-badge amf-critical">FICHIER PRINCIPAL ABSENT</span>';
		if ( $scan && ! $this->usage_results_ready( $scan ) ) return '<span class="amf-badge amf-pending">⏳ UTILISATIONS À ANALYSER</span>';
		if ( ! empty( $row['usage_confirmed'] ) ) return '<span class="amf-badge amf-ok">UTILISATION CONFIRMÉE</span>';
		if ( ! empty( $row['usage_probable'] ) ) return '<span class="amf-badge amf-probable">ASSOCIATION PROBABLE</span>';
		return '<span class="amf-badge amf-warning">⚠ À CONTRÔLER — AUCUNE UTILISATION DÉTECTÉE</span>';
	}

	private function family_state_badge( $row ) {
		if ( empty( $row['attachment_id'] ) ) {
			if ( ! empty( $row['direct_usage_count'] ) ) return '<span class="amf-badge amf-ok">URL DIRECTE DÉTECTÉE</span>';
			if ( ! empty( $row['ambiguous_count'] ) ) return '<span class="amf-badge amf-manual">À CONTRÔLER — ASSOCIATION AMBIGUË</span>';
			return '<span class="amf-badge amf-warning">⚠ À CONTRÔLER EN PRIORITÉ</span>';
		}
		if ( (int) $row['max_confidence'] < 100 ) return '<span class="amf-badge amf-probable">ASSOCIATION PROBABLE</span>';
		return '<span class="amf-badge amf-ok">ATTACHMENT IDENTIFIÉ</span>';
	}

	private function severity_badge( $severity ) {
		$map = array(
			'critical'    => array( 'amf-critical', '🔴 CRITIQUE' ),
			'regenerable' => array( 'amf-regenerable', '🟡 RÉGÉNÉRABLE' ),
			'manual'      => array( 'amf-manual', '🟠 INTERVENTION MANUELLE' ),
			'warning'     => array( 'amf-warning', '⚠ AVERTISSEMENT' ),
		);
		$item = isset( $map[ $severity ] ) ? $map[ $severity ] : $map['warning'];
		return '<span class="amf-badge ' . esc_attr( $item[0] ) . '">' . esc_html( $item[1] ) . '</span>';
	}

	private function classification_label( $classification ) {
		$labels = array(
			'wp_original' => 'Original WP', 'wp_original_source' => 'Source originale WP', 'wp_thumbnail' => 'Thumbnail WP actuel',
			'wp_metadata_variant' => 'Variante déclarée dans les métadonnées WP', 'wp_backup' => 'Sauvegarde WP',
			'legacy_thumbnail' => 'Ancien dérivé / thumbnail probable', 'legacy_variant' => 'Ancienne variante probable',
			'conversion_webp' => 'Conversion WebP probable', 'conversion_avif' => 'Conversion AVIF probable',
			'family_derivative' => 'Fichier de famille probable', 'unassociated' => 'Fichier physique non associé', 'unassociated_ambiguous' => 'Association ambiguë',
		);
		return isset( $labels[ $classification ] ) ? $labels[ $classification ] : $classification;
	}

	private function action_links( $edit_url, $view_url, $file_url = '' ) {
		$links = array();
		if ( $edit_url ) $links[] = '<a href="' . esc_url( $edit_url ) . '">Modifier</a>';
		if ( $view_url ) $links[] = '<a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">Voir en front</a>';
		if ( $file_url ) $links[] = '<a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">Voir le fichier</a>';
		return $links ? implode( ' · ', $links ) : '—';
	}

	private function get_per_page( $default = 50 ) {
		$allowed = array( 25, 50, 75, 100, 200, 500 );
		$value = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : absint( $default ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $value, $allowed, true ) ? $value : absint( $default );
	}

    private function render_filter_form( $tab, $selects, $search, $total, $per_page ) {
        $advanced = array();
        foreach (array('assoc','state') as $name) {
            if (isset($selects[$name])) { $advanced[$name]=$selects[$name]; unset($selects[$name]); }
        }
        echo '<form method="get" class="amf-filters amf-client-filters"><input type="hidden" name="page" value="audit-medias"><input type="hidden" name="tab" value="'.esc_attr($tab).'"><input type="hidden" name="per_page" value="'.esc_attr($per_page).'">';
        foreach (array('orderby','order') as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(sanitize_key(wp_unslash($_GET[$key]))).'">'; }
        }
        if (false !== $search) { echo '<label class="screen-reader-text" for="amf-search">Rechercher un média</label><input id="amf-search" type="search" name="s" value="'.esc_attr($search).'" placeholder="Rechercher une image…">'; }
        foreach ($selects as $name => $options) { $this->render_filter_select($name,$options); }
        echo '<button class="button button-primary">Filtrer</button><a class="amf-reset-filters" href="'.esc_url(admin_url('tools.php?page=audit-medias&tab='.$tab.'&per_page='.$per_page)).'">Réinitialiser</a><span class="amf-filter-count">'.esc_html(number_format_i18n($total)).' résultat(s)</span>';
        if ($advanced) {
            $open = false;
            foreach ($advanced as $name => $options) { if (isset($_GET[$name]) && is_scalar($_GET[$name]) && '' !== (string)$_GET[$name]) { $open=true; } }
            echo '<details class="amf-advanced-filters"'.($open ? ' open' : '').'><summary>Filtres avancés</summary><div>';
            foreach ($advanced as $name => $options) { $this->render_filter_select($name,$options); }
            echo '</div></details>';
        }
        echo '</form>';
    }

    private function render_filter_select( $name, $options ) {
        $current=isset($_GET[$name]) && is_scalar($_GET[$name]) ? sanitize_key(wp_unslash($_GET[$name])) : '';
        $labels=array('wc_usage'=>'Affectation aux images produits','other_usage'=>'Utilisations sur le site','assoc'=>'Association des fichiers','state'=>'État des fichiers');
        echo '<select name="'.esc_attr($name).'" aria-label="'.esc_attr(isset($labels[$name]) ? $labels[$name] : $name).'">';
        foreach ($options as $value => $label) { echo '<option value="'.esc_attr($value).'" '.selected($current,$value,false).'>'.esc_html($label).'</option>'; }
        echo '</select>';
    }

	private function pagination( $total, $per_page, $paged ) {
		$total_pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		$links = array();
		if ( $total_pages > 1 ) {
			$args = array();
			foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( is_scalar( $value ) && 'paged' !== $key ) $args[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
			$args['paged'] = 999999999;
			$base = add_query_arg( $args, admin_url( 'tools.php' ) );
			$base = str_replace( '999999999', '%#%', $base );
			$links = paginate_links( array( 'base' => $base, 'format' => '', 'current' => $paged, 'total' => $total_pages, 'type' => 'array', 'prev_text' => '‹ Précédent', 'next_text' => 'Suivant ›', 'mid_size' => 2, 'end_size' => 1 ) );
		}
		echo '<nav class="amf-pagination" aria-label="Pagination">';
		$this->render_per_page_selector( $per_page );
		if ( $links ) {
			echo '<div class="amf-pagination-links">';
			foreach ( $links as $link ) echo wp_kses_post( $link );
			echo '</div>';
		}
		echo '</nav>';
	}

	private function render_per_page_selector( $per_page ) {
		$allowed = array( 25, 50, 75, 100, 200, 500 );
		echo '<form method="get" class="amf-per-page-form">';
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! is_scalar( $value ) || in_array( $key, array( 'paged', 'per_page' ), true ) ) continue;
			echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $value ) ) ) . '">';
		}
		echo '<label>Lignes par page <select name="per_page" onchange="this.form.submit()">';
		foreach ( $allowed as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $per_page, $value, false ) . '>' . esc_html( $value ) . '</option>';
		}
		echo '</select></label><noscript><button class="button">Appliquer</button></noscript></form>';
	}

    private function media_preview_html( $attachment_id, $rel_path = '', $size = 56 ) {
        $url = '';
        if ($attachment_id) {
            $src = wp_get_attachment_image_src($attachment_id, array($size,$size), false);
            if (is_array($src) && !empty($src[0])) { $url=$src[0]; }
        }
        if (!$url && $rel_path) { $url=AMF_Utils::file_url($rel_path); }
        if (!$url) { return '<span class="amf-thumb amf-thumb-placeholder">Aperçu indisponible</span>'; }
        // No misleading MIME icon nor external srcset rewriting for this local audit preview.
        return '<img class="amf-thumb" width="'.esc_attr($size).'" height="'.esc_attr($size).'" loading="lazy" decoding="async" src="'.esc_url($url).'" alt="">';
    }

	private function media_cell_html( $attachment_id, $rel_path, $title, $subtitle = '' ) {
		return '<div class="amf-media-cell">' . $this->media_preview_html( $attachment_id, $rel_path, 56 ) . '<div><strong>' . esc_html( $title ?: 'Image' ) . '</strong>' . ( $subtitle ? '<br><small>' . esc_html( $subtitle ) . '</small>' : '' ) . '</div></div>';
	}

	private function attachment_or_file_url( $attachment_id, $rel_path = '' ) {
		if ( $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) return $url;
		}
		return $rel_path ? AMF_Utils::file_url( $rel_path ) : '';
	}

	private function files_sort_header( $label, $key, $current_orderby, $current_order, $default_order = 'desc', $extra_class = '' ) {
		$default_order = 'asc' === strtolower( $default_order ) ? 'asc' : 'desc';
		$is_current = $current_orderby === $key;
		$current = 'ASC' === strtoupper( $current_order ) ? 'asc' : 'desc';
		$next_order = $is_current ? ( 'asc' === $current ? 'desc' : 'asc' ) : $default_order;
		$args = array(
			'page'    => 'audit-medias',
			'tab'     => isset($_GET['tab']) && 'media' === $_GET['tab'] ? 'media' : 'files',
			'orderby' => $key,
			'order'   => $next_order,
			'paged'   => 1,
		);
		foreach ( array( 'assoc', 's', 'per_page', 'wc_usage', 'other_usage', 'state' ) as $name ) {
			if ( isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		$url = add_query_arg( $args, admin_url( 'tools.php' ) );
		$indicator = '';
		if ( $is_current ) {
			$indicator = ' <span class="amf-sort-indicator" aria-hidden="true">' . ( 'asc' === $current ? '▲' : '▼' ) . '</span>';
		}
		$class = trim( 'amf-sortable ' . $extra_class . ( $is_current ? ' amf-sorted' : '' ) );
		$aria_sort = $is_current ? ( 'asc' === $current ? 'ascending' : 'descending' ) : 'none';
		return '<th class="' . esc_attr( $class ) . '" aria-sort="' . esc_attr( $aria_sort ) . '"><a class="amf-sortable-link" href="' . esc_url( $url ) . '" title="Trier cette colonne"><span>' . esc_html( $label ) . '</span>' . $indicator . '</a></th>';
	}

	private function family_delete_block_reason( $scan, $row ) {
		if ( ! $this->actions->deletion_enabled() ) return 'Activez la suppression manuelle dans Réglages.';
		if ( ! $scan || 'complete' !== $scan['status'] ) return 'La suppression est disponible uniquement après une analyse terminée.';
		if ( ! AMF_Reference_Parser::scan_is_current( $scan ) ) return 'Recommencez l’analyse : nouveaux shortcodes et URL CDN non vérifiés.';
		if ( empty( $row['family_key'] ) ) return 'Clé de famille absente : relancez une analyse avec la version actuelle.';
		return 'Une vérification supplémentaire sera réalisée au moment de la suppression.';
	}

	private function render_family_files_table( $files ) {
		?><div class="amf-table-wrap"><table class="widefat striped amf-subtable"><thead><tr><th class="amf-col-media">Média</th><th>Fichier</th><th>Classification</th><th>Confiance</th><th>Références directes</th><th>Taille</th><th>Actions</th></tr></thead><tbody>
		<?php if ( ! $files ) : ?><tr><td colspan="7">Aucun fichier physique correspondant.</td></tr><?php endif; ?>
		<?php foreach ( $files as $file ) : $url = AMF_Utils::file_url( $file['rel_path'] ); ?>
			<tr><td><?php echo $this->media_preview_html( 0, $file['rel_path'], 44 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><code><?php echo esc_html( $file['rel_path'] ); ?></code></td><td><?php echo esc_html( $this->classification_label( $file['classification'] ) ); ?><br><small><?php echo esc_html( $file['note'] ); ?></small></td><td><?php echo esc_html( $file['association_confidence'] ); ?>%</td><td><?php echo ! empty( $file['direct_usage_count'] ) ? '<span class="amf-badge amf-ok">URL UTILISÉE × ' . esc_html( $file['direct_usage_count'] ) . '</span>' : '0'; ?></td><td><?php echo esc_html( AMF_Utils::human_bytes( $file['bytes'] ) ); ?></td><td><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Voir le fichier</a><?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div><?php
	}

	private function delete_media_button_html( $scan, $row, $as_button = false ) {
		if ( ! $scan || 'complete' !== $scan['status'] || ! AMF_Reference_Parser::scan_is_current($scan) || ! $this->actions->deletion_enabled() ) return '';
		if ( (int) $row['usage_confirmed'] > 0 || (int) $row['usage_probable'] > 0 ) return '';
		$class = $as_button ? 'button button-link-delete amf-delete-media' : 'button-link-delete amf-delete-media';
		return ' · <button type="button" class="' . esc_attr( $class ) . '" data-scan-id="' . esc_attr( $scan['id'] ) . '" data-attachment-id="' . esc_attr( $row['attachment_id'] ) . '">Supprimer</button>';
	}

	private function family_can_delete( $scan, $row ) {
		return $scan && 'complete' === $scan['status'] && AMF_Reference_Parser::scan_is_current($scan) && $this->actions->deletion_enabled() && ! empty( $row['family_key'] );
	}
}
