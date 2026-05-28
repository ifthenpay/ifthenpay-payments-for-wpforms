<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayEmailHelper
{
   const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

   private function __construct() {}

   /**
    * Sends activation email to support.
    *
    * @param array $data {
    *   @type string gateway_key      Gateway key.
    *   @type string entity           Method/entity to activate.
    *   @type string backoffice_key   Backoffice access key.
    *   @type string customer_email   Customer's email.
    *   @type string site_url         Store URL.
    *   @type string site_name        Site name.
    *   @type string wp_version       WordPress version.
    *   @type string wpforms_version WPForms version.
    *   @type string plugin_version   ifthenpay module version.
    * }
    * @return bool True if sent successfully, false otherwise.
    */
   public static function send_iftp_wpf_activation_email(array $data): bool
   {
        $entity = strtoupper(sanitize_text_field($data['entity'] ?? ''));
        $siteUrl = esc_url_raw($data['site_url'] ?? home_url('/'));
        $recipient = self::get_support_email($data);

        $subject = sprintf(
            '[dev_ifthenpay] [%s]: Ativacao de Servico',
            $entity
        );

      // Email body items
      $items = [
            'Chave de acesso ao backoffice:' => esc_html($data['backoffice_key'] ?? ''),
            'Gateway Key:' => esc_html($data['gateway_key'] ?? ''),
            'Email Cliente:' => esc_html($data['customer_email'] ?? ''),
            'Metodo a ativar:' => esc_html($entity),
            'Loja online:' => esc_url($siteUrl),
            'Plataforma ecommerce:' => sprintf(
                'WordPress %s / WPForms v%s',
                esc_html($data['wp_version'] ?? ''),
                esc_html($data['wpforms_version'] ?? '')
            ),
            'Versao do Modulo ifthenpay:' => esc_html($data['plugin_version'] ?? ''),
            'Atualizar Conta Cliente:' => 'Apos adicionar o metodo nao precisa tomar mais nenhuma acao, este metodo ficara disponivel para selecao na pagina de configuracao da extensao.',
        ];

      // HTML Body
      ob_start();
?>
      <div style="
    font-family: Arial, sans-serif;
    color: #333;
    background-color: #f9f9f9;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    max-width: 600px;
    margin: auto;
">
         <h2 style="
      margin-top: 0;
      font-size: 20px;
      line-height: 1.2;
    ">
            Ativar método de pagamento para a Gateway
            <span style="color: #d32f2f;">
               <?php echo esc_html($data['gateway_key']); ?>
            </span>
         </h2>

         <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse: collapse;">
            <?php foreach ($items as $label => $value) : ?>
               <tr>
                  <td style="
        padding: 8px 0;
        vertical-align: top;
        width: 200px;
        font-weight: bold;
      ">
                     <?php echo esc_html($label); ?>
                  </td>
                  <td style="
        padding: 8px 0;
      ">
                     <?php echo esc_html($value); ?>
                  </td>
               </tr>
            <?php endforeach; ?>
         </table>

         <p style="
      margin-top: 20px;
      font-size: 12px;
      color: #777;
      text-align: center;
    ">
            Pedido gerado automaticamente pelo módulo ifthenpay
         </p>
      </div>
<?php
      $body = ob_get_clean();

      $host = wp_parse_url($data['site_url'], PHP_URL_HOST);
      $headers = [
         'Content-Type: text/html; charset=UTF-8',
         'From: ' . esc_html($data['site_name']) . ' <no-reply@' . $host . '>',
      ];

      $result = wp_mail($recipient, $subject, $body, $headers);
      return $result;
   }

    /**
     * @param array<string, string> $data
     */
    private static function get_support_email(array $data): string
    {
        $email = apply_filters('iftp_pbl_support_email', self::SUPPORT_EMAIL, $data);

        return is_string($email) && is_email($email) ? $email : self::SUPPORT_EMAIL;
    }
}
