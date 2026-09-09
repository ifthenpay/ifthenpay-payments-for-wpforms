<a name="top"></a>

# ifthenpay | Payments for WPForms

🇬🇧 [English](#user-content-en) &nbsp;|&nbsp; 🇵🇹 [Português](#user-content-pt) &nbsp;|&nbsp; 🇪🇸 [Español](#user-content-es) &nbsp;|&nbsp; 🇫🇷 [Français](#user-content-fr)

Adds ifthenpay payment methods to WPForms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

---

<details name="lang" open>
<summary><strong>🇬🇧 English</strong></summary>

<a name="en"></a>

## Table of Contents

- [Description](#user-content-en-description)
- [Key Features](#user-content-en-key-features)
- [Requirements](#user-content-en-requirements)
- [Installation](#user-content-en-installation)
- [Using ifthenpay Alongside Another Payment Gateway](#user-content-en-using-ifthenpay-alongside-another-payment-gateway)
- [Popup Confirmation Messages](#user-content-en-popup-confirmation-messages)
- [Frequently Asked Questions](#user-content-en-frequently-asked-questions)
- [External Services](#user-content-en-external-services)
- [Screenshots](#user-content-en-screenshots)
- [Support](#user-content-en-support)

<a name="en-description"></a>
### Description

This plugin integrates the ifthenpay payment gateway with WPForms to enable seamless payment collection directly from your forms. Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website. Customers can complete payments using their preferred method via a secure payment page. After submitting a form, users are redirected to ifthenpay's secure hosted payment page to complete the transaction; ifthenpay then sends a server-side callback to update the payment status automatically.

**In plain terms you get:**

- One-time payments directly from WPForms
- Support for coupons and automatic total calculations
- Merchant backoffice (basic sales) on web + mobile
- Secure automatic payment confirmations (no card numbers stored)

All settings are made in WPForms and in your ifthenpay Backoffice. The plugin is built so site owners can manage payments without needing deep technical knowledge.

<a name="en-key-features"></a>
### Key Features

1. Full integration with WPForms Lite and Pro payment flow
2. Secure transactions
3. Automatic payment confirmation
4. Support for multiple payment methods (cards, wallets, transfers)
5. Coupon and discount support via WPForms
6. Secure full-page redirect to ifthenpay's hosted payment page
7. Real-time payment status in WPForms entries
8. Multi-language support (EN, ES, FR, PT)
9. Security first (no card data stored)

<a name="en-requirements"></a>
### Requirements

- An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
- A WPForms Gateway Key (request this from ifthenpay support/helpdesk).
- The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
- WordPress 6.5+ and PHP 8.2+, and WPForms installed and activated.
- HTTPS (SSL) enabled on your site.

<a name="en-installation"></a>
### Installation

1. **Install:** Upload the plugin zip via `Plugins → Add New → Upload`, or install from WordPress.org and Activate.
2. **Credentials:** Ensure your ifthenpay account has an active WPForms Gateway Key with the desired payment methods enabled.
3. **Setup:** Go to `WPForms → Settings → Payments` and enter your Backoffice Key.
4. **Form config:** `Create/Edit a form → Payments tab → Add the Ifthenpay field on your form → enable "ifthenpay | Payment Gateway"` and select a Gateway Key. Next, choose which payment methods to activate from those available in your gateway, and set your default payment method. Finally, add a payment description, which will be displayed on the ifthenpay payment page for all transactions.

<a name="en-using-ifthenpay-alongside-another-payment-gateway"></a>
### Using ifthenpay Alongside Another Payment Gateway

WPForms only allows one active payment method per submission. If a form has both the ifthenpay field and another payment gateway's field (PayPal, Stripe, Square, or Authorize.Net) visible at the same time, the ifthenpay field automatically hides itself — logo, payment methods, and "Pay now" button — so customers only ever see the one payment option that will actually work, instead of a button that would fail or double-charge them.

- The ifthenpay field reappears automatically if the other gateway's field becomes hidden again (for example, through WPForms conditional logic), with no page reload required.
- This only applies to gateway fields that are actually visible on the form. A gateway field that's present but hidden by conditional logic does not trigger this behavior.
- Built-in WPForms fields like Total, Coupon, and single/multiple/checkbox/select payment items are never treated as competing gateways — only PayPal, Stripe, Square, and Authorize.Net fields are.

<a name="en-popup-confirmation-messages"></a>
### Popup Confirmation Messages

Each ifthenpay-enabled form has its own **Popup Confirmation Messages**, configured in `Create/Edit a form → Payments tab`. These control what customers see in the ifthenpay payment popup for each outcome, and are separate from — and not affected by — WPForms' own Confirmations tab.

- Customize the message shown for each of the four outcomes: **Paid**, **Pending**, **Failed**, and **Cancelled**. Each one is pre-filled with sensible default text that you can edit or leave as-is.
- For the **Paid** outcome only, choose a Confirmation Type: show a **Message** in the popup, redirect to a WPForms **Page**, or redirect to a **Redirect URL** — the same flexibility as WPForms' native confirmations.
- Optionally enable **Show entry preview after confirmation message** on the Paid outcome, so customers see a summary of their submitted entry right after the payment confirmation message.

<a name="en-frequently-asked-questions"></a>
### Frequently Asked Questions

<details>
<summary><strong>Does this plugin require WPForms?</strong></summary>
Yes. WPForms must be installed and active to use this plugin.
</details>

<details>
<summary><strong>Does it support recurring payments?</strong></summary>
No. This version supports only one-time payments via pay-by-link.
</details>

<details>
<summary><strong>Are payment details stored?</strong></summary>
No. The plugin does not store card numbers or full bank details. Only minimal references required for payment matching are kept.
</details>

<details>
<summary><strong>Does it support WPForms coupons?</strong></summary>
Yes. WPForms coupon fields are fully supported and discounts are automatically calculated.
</details>

<details>
<summary><strong>Which payment methods are supported?</strong></summary>
Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>How does the payment process work?</strong></summary>
After form submission, users are redirected to a secure ifthenpay-hosted payment page. Once payment is completed, the status is updated automatically via callback.
</details>

<details>
<summary><strong>What happens if a payment fails?</strong></summary>
The entry is marked as Failed. Users can retry the payment depending on your configuration.
</details>

<details>
<summary><strong>Can I customize the payment experience?</strong></summary>
Yes. You can configure button label, description, and styling within WPForms, plus a separate Popup Confirmation Message for each payment outcome (Paid, Pending, Failed, Cancelled). See <a href="#user-content-en-popup-confirmation-messages">Popup Confirmation Messages</a>.
</details>

<details>
<summary><strong>What happens if my form also has another payment gateway field (PayPal, Stripe, Square, Authorize.Net)?</strong></summary>
The ifthenpay field hides itself automatically while that other gateway's field is visible, so customers are never shown two active payment options at once. See <a href="#user-content-en-using-ifthenpay-alongside-another-payment-gateway">Using ifthenpay Alongside Another Payment Gateway</a>.
</details>

<details>
<summary><strong>Is there a sandbox?</strong></summary>
ifthenpay may provide test entities; if unavailable, use a low-value live test.
</details>

<details>
<summary><strong>How secure is the integration?</strong></summary>
Requests are encrypted over HTTPS; no sensitive payment data is stored.
</details>

<details>
<summary><strong>Does ifthenpay's addon for WPForms accept WEBHOOKS(Callbacks)?</strong></summary>
Yes! ifthenpay's addon for WPForms from version 2.0.0 and future ones accepts webhook(callbacks).
</details>

<a name="en-external-services"></a>
### External Services

This plugin integrates with the ifthenpay payment platform to process payments for WPForms submissions. ifthenpay is a third-party service that provides secure payment processing for cards, wallets, and local bank transfers.

- **WPForms**
  - **What it is and what it is used for**: A form builder plugin used to create payment forms. This plugin extends its payment capabilities.

- **ifthenpay Backoffice & Integrations**
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
  - **What data is sent and when**:
    - During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    - During payment processing: Order reference ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method, customer email, customer name, and form field data.
    - During webhook registration: the Gateway Key and this site's callback URL, so ifthenpay can notify the site directly when a payment resolves.
    - During payment method activation requests: when an admin requests activation of a new payment method from `WPForms → Settings → Payments`, an email is sent to ifthenpay support (suporte@ifthenpay.com) containing the Backoffice Key, Gateway Key, the requested payment method, the admin's email address, site URL, site name, WordPress version, WPForms version, and plugin version.
    - During callbacks: Payment status and payment method.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

<a name="en-screenshots"></a>
### Screenshots

Below are screenshots demonstrating key features and interfaces of the plugin:

1. **(Admin Only) Backoffice Synchronization under WPForms Settings Payments**
   ![Backoffice Settings](.wordpress-org/screenshot-1.png)
2. **(Admin Only) WPForms's admin page (Creation/Editing Form -> Payments)**
   ![Gateway Settings](.wordpress-org/screenshot-2.png)
3. **(Admin Only) Adding ifthenpay's Payment field to the selected form**
   ![Adding Field to Form](.wordpress-org/screenshot-3.png)
4. **(Customers Experience) Payment Gateway field display varies by WPForms settings**
   ![Display of Field](.wordpress-org/screenshot-4.png)
5. **(Customers Experience) ifthenpay's Secure Payment Page**
   ![Payment Page](.wordpress-org/screenshot-5.png)
6. **(Customers Experience) Payment Message (either paid, pending, cancelled or failed)**
   ![Display of Payment Message](.wordpress-org/screenshot-6.png)
7. **(Admin Only) Payment Details**
   ![Payment Details](.wordpress-org/screenshot-7.png)
8. **(Admin Only) Payment Entries**
   ![Payment Entries](.wordpress-org/screenshot-8.png)

<a name="en-support"></a>
### Support

For assistance use the [WordPress.org support forum](https://wordpress.org/support):

Pre-checks:

- Payment method enabled on Gateway Key AND mapped to Integration
- Running current recommended versions of WordPress, PHP, & WPForms

Commercial helpdesk available (no direct email required): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **ifthenpay support**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **WPForms docs**: [WPForms docs](https://wpforms.com/docs/)

[⬆ Change language](#user-content-top)

</details>

<details name="lang">
<summary><strong>🇵🇹 Português</strong></summary>

<a name="pt"></a>

## Índice

- [Descrição](#user-content-pt-descricao)
- [Principais Funcionalidades](#user-content-pt-principais-funcionalidades)
- [Requisitos](#user-content-pt-requisitos)
- [Instalação](#user-content-pt-instalacao)
- [Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento](#user-content-pt-usar-outro-gateway)
- [Mensagens de Confirmação no Popup](#user-content-pt-mensagens-confirmacao-popup)
- [Perguntas Frequentes](#user-content-pt-faq)
- [Serviços Externos](#user-content-pt-servicos-externos)
- [Capturas de Ecrã](#user-content-pt-capturas)
- [Suporte](#user-content-pt-suporte)

<a name="pt-descricao"></a>
### Descrição

Este plugin integra o gateway de pagamento ifthenpay com o WPForms para permitir a recolha de pagamentos diretamente nos seus formulários. Os pagamentos são processados através de um sistema seguro de pay-by-link, garantindo que nenhum dado sensível de cartão ou dados bancários é armazenado no seu website. Os clientes podem concluir o pagamento com o método que preferirem através de uma página de pagamento segura. Após submeter um formulário, os utilizadores são redirecionados para a página de pagamento segura e alojada pela ifthenpay para concluir a transação; a ifthenpay envia depois um callback do lado do servidor para atualizar automaticamente o estado do pagamento.

**Em termos simples, obtém:**

- Pagamentos únicos diretamente a partir do WPForms
- Suporte para cupões e cálculo automático de totais
- Backoffice do comerciante (vendas básicas) na web e em dispositivos móveis
- Confirmações de pagamento automáticas e seguras (sem armazenamento de números de cartão)

Todas as configurações são feitas no WPForms e no seu Backoffice ifthenpay. O plugin foi criado para que os proprietários de sites possam gerir pagamentos sem necessitar de conhecimentos técnicos avançados.

<a name="pt-principais-funcionalidades"></a>
### Principais Funcionalidades

1. Integração completa com o fluxo de pagamento do WPForms Lite e Pro
2. Transações seguras
3. Confirmação automática de pagamento
4. Suporte para múltiplos métodos de pagamento (cartões, carteiras digitais, transferências)
5. Suporte a cupões e descontos através do WPForms
6. Redirecionamento seguro de página inteira para a página de pagamento alojada pela ifthenpay
7. Estado do pagamento em tempo real nas entradas do WPForms
8. Suporte multi-idioma (EN, ES, FR, PT)
9. Segurança em primeiro lugar (sem armazenamento de dados de cartão)

<a name="pt-requisitos"></a>
### Requisitos

- Uma conta de comerciante ifthenpay ativa — [subscreva aqui](https://ifthenpay.com/aderir/) para obter as suas credenciais.
- Uma Chave de Gateway WPForms (solicite-a ao suporte/helpdesk da ifthenpay).
- Os métodos de pagamento que pretende ativar nessa Chave de Gateway (a nossa equipa de helpdesk irá orientá-lo).
- WordPress 6.5+ e PHP 8.2+, com o WPForms instalado e ativado.
- HTTPS (SSL) ativado no seu site.

<a name="pt-instalacao"></a>
### Instalação

1. **Instalar:** Carregue o ficheiro zip do plugin em `Plugins → Adicionar Novo → Carregar`, ou instale a partir do WordPress.org, e ative-o.
2. **Credenciais:** Certifique-se de que a sua conta ifthenpay tem uma Chave de Gateway WPForms ativa com os métodos de pagamento pretendidos ativados.
3. **Configuração:** Vá a `WPForms → Definições → Pagamentos` e introduza a sua Backoffice Key.
4. **Configuração do formulário:** `Criar/Editar um formulário → separador Pagamentos → Adicionar o campo Ifthenpay ao formulário → ativar "ifthenpay | Payment Gateway"` e selecione uma Chave de Gateway. De seguida, escolha quais os métodos de pagamento a ativar de entre os disponíveis no seu gateway, e defina o seu método de pagamento predefinido. Por fim, adicione uma descrição de pagamento, que será apresentada na página de pagamento ifthenpay em todas as transações.

<a name="pt-usar-outro-gateway"></a>
### Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento

O WPForms apenas permite um método de pagamento ativo por submissão. Se um formulário tiver simultaneamente o campo ifthenpay e o campo de outro gateway de pagamento (PayPal, Stripe, Square ou Authorize.Net) visíveis ao mesmo tempo, o campo ifthenpay oculta-se automaticamente — logótipo, métodos de pagamento e botão "Pagar agora" — para que os clientes vejam sempre apenas a opção de pagamento que realmente funcionará, em vez de um botão que falharia ou cobraria duas vezes.

- O campo ifthenpay volta a aparecer automaticamente se o campo do outro gateway ficar novamente oculto (por exemplo, através da lógica condicional do WPForms), sem ser necessário recarregar a página.
- Isto aplica-se apenas a campos de gateway que estejam efetivamente visíveis no formulário. Um campo de gateway presente mas oculto por lógica condicional não desencadeia este comportamento.
- Campos nativos do WPForms como Total, Cupão e itens de pagamento de escolha única/múltipla/checkbox/lista nunca são tratados como gateways concorrentes — apenas os campos PayPal, Stripe, Square e Authorize.Net o são.

<a name="pt-mensagens-confirmacao-popup"></a>
### Mensagens de Confirmação no Popup

Cada formulário com o ifthenpay ativado tem as suas próprias **Mensagens de Confirmação no Popup**, configuradas em `Criar/Editar um formulário → separador Pagamentos`. Estas controlam o que os clientes veem no popup de pagamento da ifthenpay para cada resultado, e são independentes do separador nativo de Confirmações do WPForms, que não afeta este popup.

- Personalize a mensagem apresentada para cada um dos quatro resultados: **Pago**, **Pendente**, **Falhado** e **Cancelado**. Cada uma começa preenchida com um texto predefinido sensato, que pode editar ou manter como está.
- Apenas para o resultado **Pago**, escolha um Tipo de Confirmação: apresentar uma **Mensagem** no popup, redirecionar para uma **Página** do WPForms, ou redirecionar para um **URL de Redirecionamento** — com a mesma flexibilidade das confirmações nativas do WPForms.
- Opcionalmente, ative **Mostrar pré-visualização da entrada após a mensagem de confirmação** no resultado Pago, para que os clientes vejam um resumo da entrada submetida logo após a mensagem de confirmação do pagamento.

<a name="pt-faq"></a>
### Perguntas Frequentes

<details>
<summary><strong>Este plugin requer o WPForms?</strong></summary>
Sim. O WPForms tem de estar instalado e ativo para utilizar este plugin.
</details>

<details>
<summary><strong>Suporta pagamentos recorrentes?</strong></summary>
Não. Esta versão suporta apenas pagamentos únicos através de pay-by-link.
</details>

<details>
<summary><strong>Os dados de pagamento são armazenados?</strong></summary>
Não. O plugin não armazena números de cartão nem dados bancários completos. Apenas são guardadas as referências mínimas necessárias para associar o pagamento.
</details>

<details>
<summary><strong>Suporta cupões do WPForms?</strong></summary>
Sim. Os campos de cupão do WPForms são totalmente suportados e os descontos são calculados automaticamente.
</details>

<details>
<summary><strong>Que métodos de pagamento são suportados?</strong></summary>
Qualquer método ifthenpay associado à sua Chave de Gateway (por exemplo, Multibanco, MB WAY, Payshop, Cartão de Crédito, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>Como funciona o processo de pagamento?</strong></summary>
Após a submissão do formulário, os utilizadores são redirecionados para uma página de pagamento segura alojada pela ifthenpay. Assim que o pagamento é concluído, o estado é atualizado automaticamente através de callback.
</details>

<details>
<summary><strong>O que acontece se um pagamento falhar?</strong></summary>
A entrada é marcada como Falhada. Os utilizadores podem tentar novamente o pagamento, dependendo da sua configuração.
</details>

<details>
<summary><strong>Posso personalizar a experiência de pagamento?</strong></summary>
Sim. Pode configurar o texto do botão, a descrição e o estilo dentro do WPForms, além de uma Mensagem de Confirmação no Popup separada para cada resultado do pagamento (Pago, Pendente, Falhado, Cancelado). Consulte <a href="#user-content-pt-mensagens-confirmacao-popup">Mensagens de Confirmação no Popup</a>.
</details>

<details>
<summary><strong>O que acontece se o meu formulário também tiver outro campo de gateway de pagamento (PayPal, Stripe, Square, Authorize.Net)?</strong></summary>
O campo ifthenpay oculta-se automaticamente enquanto o campo do outro gateway estiver visível, para que os clientes nunca vejam duas opções de pagamento ativas ao mesmo tempo. Consulte <a href="#user-content-pt-usar-outro-gateway">Utilizar o ifthenpay em Conjunto com Outro Gateway de Pagamento</a>.
</details>

<details>
<summary><strong>Existe uma sandbox?</strong></summary>
A ifthenpay pode disponibilizar entidades de teste; caso não estejam disponíveis, utilize um teste real de baixo valor.
</details>

<details>
<summary><strong>Quão segura é a integração?</strong></summary>
Os pedidos são encriptados via HTTPS; nenhum dado de pagamento sensível é armazenado.
</details>

<details>
<summary><strong>O addon da ifthenpay para o WPForms aceita WEBHOOKS (Callbacks)?</strong></summary>
Sim! O addon da ifthenpay para o WPForms a partir da versão 2.0.0, e versões futuras, aceita webhooks (callbacks).
</details>

<a name="pt-servicos-externos"></a>
### Serviços Externos

Este plugin integra-se com a plataforma de pagamentos ifthenpay para processar pagamentos das submissões do WPForms. A ifthenpay é um serviço de terceiros que disponibiliza processamento seguro de pagamentos por cartão, carteiras digitais e transferências bancárias locais.

- **WPForms**
  - **O que é e para que é utilizado**: Um plugin de criação de formulários utilizado para criar formulários de pagamento. Este plugin estende as suas capacidades de pagamento.

- **Backoffice e Integrações ifthenpay**
  - **O que é e para que é utilizado**: O Backoffice ifthenpay é o painel do comerciante utilizado para gerir integrações e configurações de pagamento. O plugin utiliza a API da ifthenpay para gerar links de pagamento e validar transações.
  - **Que dados são enviados e quando**:
    - Durante a configuração: Backoffice Key e Gateway Key para autenticação e obtenção da configuração.
    - Durante o processamento do pagamento: ID de referência da encomenda, montante, descrição, contas de métodos de pagamento ativos, URLs de retorno de sucesso/erro/cancelamento, idioma e, opcionalmente, o método de pagamento selecionado, o e-mail do cliente, o nome do cliente e dados dos campos do formulário.
    - Durante o registo do webhook: a Gateway Key e o URL de callback deste site, para que a ifthenpay possa notificar o site diretamente quando um pagamento é concluído.
    - Durante os pedidos de ativação de método de pagamento: quando um administrador solicita a ativação de um novo método de pagamento em `WPForms → Definições → Pagamentos`, é enviado um e-mail para o suporte da ifthenpay (suporte@ifthenpay.com) contendo a Backoffice Key, a Gateway Key, o método de pagamento solicitado, o endereço de e-mail do administrador, o URL do site, o nome do site, a versão do WordPress, a versão do WPForms e a versão do plugin.
    - Durante os callbacks: Estado do pagamento e método de pagamento.
  - **Contrato de Licença de Utilizador Final (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Política de Privacidade**: [Política de Privacidade](https://ifthenpay.com/politica-de-privacidade/)

Todos os pedidos de rede são realizados do lado do servidor via HTTPS. As credenciais sensíveis são armazenadas de forma segura e não são publicamente expostas. Não são armazenados dados em bruto de cartão ou bancários.

<a name="pt-capturas"></a>
### Capturas de Ecrã

Seguem-se capturas de ecrã que demonstram as principais funcionalidades e interfaces do plugin:

1. **(Apenas Admin) Sincronização do Backoffice em WPForms Settings Payments**
   ![Definições do Backoffice](.wordpress-org/screenshot-1.png)
2. **(Apenas Admin) Página de administração do WPForms (Criação/Edição de Formulário -> Pagamentos)**
   ![Definições do Gateway](.wordpress-org/screenshot-2.png)
3. **(Apenas Admin) Adicionar o campo de Pagamento ifthenpay ao formulário selecionado**
   ![Adicionar Campo ao Formulário](.wordpress-org/screenshot-3.png)
4. **(Experiência do Cliente) A apresentação do campo Gateway de Pagamento varia consoante as definições do WPForms**
   ![Apresentação do Campo](.wordpress-org/screenshot-4.png)
5. **(Experiência do Cliente) Página de Pagamento Segura da ifthenpay**
   ![Página de Pagamento](.wordpress-org/screenshot-5.png)
6. **(Experiência do Cliente) Mensagem de Pagamento (pago, pendente, cancelado ou falhado)**
   ![Apresentação da Mensagem de Pagamento](.wordpress-org/screenshot-6.png)
7. **(Apenas Admin) Detalhes do Pagamento**
   ![Detalhes do Pagamento](.wordpress-org/screenshot-7.png)
8. **(Apenas Admin) Entradas de Pagamento**
   ![Entradas de Pagamento](.wordpress-org/screenshot-8.png)

<a name="pt-suporte"></a>
### Suporte

Para assistência, utilize o [fórum de suporte do WordPress.org](https://wordpress.org/support):

Verificações prévias:

- Método de pagamento ativado na Chave de Gateway E mapeado para a Integração
- A executar as versões atuais recomendadas de WordPress, PHP e WPForms

Helpdesk comercial disponível (sem necessidade de e-mail direto): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **Suporte ifthenpay**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **Documentação do WPForms**: [WPForms docs](https://wpforms.com/docs/)

[⬆ Mudar idioma / Change language](#user-content-top)

</details>

<details name="lang">
<summary><strong>🇪🇸 Español</strong></summary>

<a name="es"></a>

## Índice

- [Descripción](#user-content-es-descripcion)
- [Características Principales](#user-content-es-caracteristicas)
- [Requisitos](#user-content-es-requisitos)
- [Instalación](#user-content-es-instalacion)
- [Usar ifthenpay Junto con Otra Pasarela de Pago](#user-content-es-usar-otra-pasarela)
- [Mensajes de Confirmación del Popup](#user-content-es-mensajes-confirmacion-popup)
- [Preguntas Frecuentes](#user-content-es-faq)
- [Servicios Externos](#user-content-es-servicios-externos)
- [Capturas de Pantalla](#user-content-es-capturas)
- [Soporte](#user-content-es-soporte)

<a name="es-descripcion"></a>
### Descripción

Este plugin integra la pasarela de pago ifthenpay con WPForms para permitir la recogida de pagos directamente desde sus formularios. Los pagos se procesan mediante un sistema seguro de pay-by-link, garantizando que no se almacenen datos sensibles de tarjetas o datos bancarios en su sitio web. Los clientes pueden completar el pago con el método que prefieran a través de una página de pago segura. Tras enviar un formulario, los usuarios son redirigidos a la página de pago segura alojada por ifthenpay para completar la transacción; a continuación, ifthenpay envía una notificación (callback) del lado del servidor para actualizar automáticamente el estado del pago.

**En términos sencillos, obtiene:**

- Pagos únicos directamente desde WPForms
- Soporte para cupones y cálculo automático de totales
- Backoffice del comerciante (ventas básicas) en web y móvil
- Confirmaciones de pago automáticas y seguras (sin almacenar números de tarjeta)

Todas las configuraciones se realizan en WPForms y en su Backoffice de ifthenpay. El plugin está diseñado para que los propietarios de sitios puedan gestionar pagos sin necesitar conocimientos técnicos avanzados.

<a name="es-caracteristicas"></a>
### Características Principales

1. Integración completa con el flujo de pago de WPForms Lite y Pro
2. Transacciones seguras
3. Confirmación automática de pago
4. Soporte para múltiples métodos de pago (tarjetas, carteras digitales, transferencias)
5. Soporte de cupones y descuentos a través de WPForms
6. Redirección segura a página completa hacia la página de pago alojada por ifthenpay
7. Estado del pago en tiempo real en las entradas de WPForms
8. Soporte multiidioma (EN, ES, FR, PT)
9. Seguridad ante todo (no se almacenan datos de tarjetas)

<a name="es-requisitos"></a>
### Requisitos

- Una cuenta de comerciante ifthenpay activa — [suscríbase aquí](https://ifthenpay.com/aderir/) para obtener sus credenciales.
- Una Clave de Pasarela de WPForms (solicítela al soporte/helpdesk de ifthenpay).
- Los métodos de pago que desea activar en esa Clave de Pasarela (nuestro equipo de helpdesk le guiará).
- WordPress 6.5+ y PHP 8.2+, con WPForms instalado y activado.
- HTTPS (SSL) habilitado en su sitio.

<a name="es-instalacion"></a>
### Instalación

1. **Instalar:** Cargue el archivo zip del plugin en `Plugins → Añadir nuevo → Subir`, o instálelo desde WordPress.org, y actívelo.
2. **Credenciales:** Asegúrese de que su cuenta ifthenpay tiene una Clave de Pasarela de WPForms activa con los métodos de pago deseados habilitados.
3. **Configuración:** Vaya a `WPForms → Ajustes → Pagos` e introduzca su Backoffice Key.
4. **Configuración del formulario:** `Crear/Editar un formulario → pestaña Pagos → Añadir el campo Ifthenpay al formulario → activar "ifthenpay | Payment Gateway"` y seleccione una Clave de Pasarela. A continuación, elija qué métodos de pago activar de entre los disponibles en su pasarela y defina su método de pago predeterminado. Por último, añada una descripción de pago, que se mostrará en la página de pago de ifthenpay en todas las transacciones.

<a name="es-usar-otra-pasarela"></a>
### Usar ifthenpay Junto con Otra Pasarela de Pago

WPForms solo permite un método de pago activo por envío. Si un formulario tiene visibles al mismo tiempo tanto el campo de ifthenpay como el campo de otra pasarela de pago (PayPal, Stripe, Square o Authorize.Net), el campo de ifthenpay se oculta automáticamente —logotipo, métodos de pago y botón "Pagar ahora"— de modo que los clientes solo vean la opción de pago que realmente funcionará, en lugar de un botón que fallaría o cobraría dos veces.

- El campo de ifthenpay vuelve a aparecer automáticamente si el campo de la otra pasarela se oculta de nuevo (por ejemplo, mediante la lógica condicional de WPForms), sin necesidad de recargar la página.
- Esto solo se aplica a los campos de pasarela que estén realmente visibles en el formulario. Un campo de pasarela presente pero oculto por lógica condicional no activa este comportamiento.
- Los campos nativos de WPForms como Total, Cupón y los elementos de pago de selección única/múltiple/casilla/lista nunca se tratan como pasarelas competidoras; solo lo son los campos de PayPal, Stripe, Square y Authorize.Net.

<a name="es-mensajes-confirmacion-popup"></a>
### Mensajes de Confirmación del Popup

Cada formulario con ifthenpay habilitado tiene sus propios **Mensajes de Confirmación del Popup**, configurados en `Crear/Editar un formulario → pestaña Pagos`. Estos controlan lo que ven los clientes en el popup de pago de ifthenpay para cada resultado, y son independientes de la pestaña nativa de Confirmaciones de WPForms, que no afecta a este popup.

- Personalice el mensaje mostrado para cada uno de los cuatro resultados: **Pagado**, **Pendiente**, **Fallido** y **Cancelado**. Cada uno comienza con un texto predeterminado razonable, que puede editar o dejar tal cual.
- Solo para el resultado **Pagado**, elija un Tipo de Confirmación: mostrar un **Mensaje** en el popup, redirigir a una **Página** de WPForms, o redirigir a una **URL de Redirección**, con la misma flexibilidad que las confirmaciones nativas de WPForms.
- Opcionalmente, active **Mostrar vista previa de la entrada después del mensaje de confirmación** en el resultado Pagado, para que los clientes vean un resumen de su entrada enviada justo después del mensaje de confirmación del pago.

<a name="es-faq"></a>
### Preguntas Frecuentes

<details>
<summary><strong>¿Este plugin requiere WPForms?</strong></summary>
Sí. WPForms debe estar instalado y activo para utilizar este plugin.
</details>

<details>
<summary><strong>¿Admite pagos recurrentes?</strong></summary>
No. Esta versión solo admite pagos únicos mediante pay-by-link.
</details>

<details>
<summary><strong>¿Se almacenan los datos de pago?</strong></summary>
No. El plugin no almacena números de tarjeta ni datos bancarios completos. Solo se guardan las referencias mínimas necesarias para conciliar el pago.
</details>

<details>
<summary><strong>¿Admite cupones de WPForms?</strong></summary>
Sí. Los campos de cupón de WPForms son totalmente compatibles y los descuentos se calculan automáticamente.
</details>

<details>
<summary><strong>¿Qué métodos de pago se admiten?</strong></summary>
Cualquier método de ifthenpay asociado a su Clave de Pasarela (por ejemplo, Multibanco, MB WAY, Payshop, Tarjeta de Crédito, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>¿Cómo funciona el proceso de pago?</strong></summary>
Tras el envío del formulario, los usuarios son redirigidos a una página de pago segura alojada por ifthenpay. Una vez completado el pago, el estado se actualiza automáticamente mediante un callback.
</details>

<details>
<summary><strong>¿Qué ocurre si un pago falla?</strong></summary>
La entrada se marca como Fallida. Los usuarios pueden reintentar el pago según su configuración.
</details>

<details>
<summary><strong>¿Puedo personalizar la experiencia de pago?</strong></summary>
Sí. Puede configurar el texto del botón, la descripción y el estilo dentro de WPForms, además de un Mensaje de Confirmación del Popup independiente para cada resultado del pago (Pagado, Pendiente, Fallido, Cancelado). Consulte <a href="#user-content-es-mensajes-confirmacion-popup">Mensajes de Confirmación del Popup</a>.
</details>

<details>
<summary><strong>¿Qué ocurre si mi formulario también tiene otro campo de pasarela de pago (PayPal, Stripe, Square, Authorize.Net)?</strong></summary>
El campo de ifthenpay se oculta automáticamente mientras el campo de la otra pasarela esté visible, de modo que los clientes nunca vean dos opciones de pago activas a la vez. Consulte <a href="#user-content-es-usar-otra-pasarela">Usar ifthenpay Junto con Otra Pasarela de Pago</a>.
</details>

<details>
<summary><strong>¿Existe un entorno de pruebas (sandbox)?</strong></summary>
ifthenpay puede proporcionar entidades de prueba; si no están disponibles, utilice una prueba real de bajo valor.
</details>

<details>
<summary><strong>¿Qué tan segura es la integración?</strong></summary>
Las solicitudes se cifran mediante HTTPS; no se almacenan datos de pago sensibles.
</details>

<details>
<summary><strong>¿El complemento de ifthenpay para WPForms admite WEBHOOKS (Callbacks)?</strong></summary>
¡Sí! El complemento de ifthenpay para WPForms a partir de la versión 2.0.0 y las siguientes admite webhooks (callbacks).
</details>

<a name="es-servicios-externos"></a>
### Servicios Externos

Este plugin se integra con la plataforma de pagos de ifthenpay para procesar los pagos de los envíos de WPForms. ifthenpay es un servicio de terceros que ofrece procesamiento seguro de pagos con tarjeta, carteras digitales y transferencias bancarias locales.

- **WPForms**
  - **Qué es y para qué se utiliza**: Un plugin de creación de formularios utilizado para crear formularios de pago. Este plugin amplía sus capacidades de pago.

- **Backoffice e Integraciones de ifthenpay**
  - **Qué es y para qué se utiliza**: El Backoffice de ifthenpay es el panel del comerciante utilizado para gestionar integraciones y configuraciones de pago. El plugin utiliza la API de ifthenpay para generar enlaces de pago y validar transacciones.
  - **Qué datos se envían y cuándo**:
    - Durante la configuración: Backoffice Key y Gateway Key para autenticación y obtención de la configuración.
    - Durante el procesamiento del pago: ID de referencia del pedido, importe, descripción, cuentas de métodos de pago habilitados, URLs de retorno de éxito/error/cancelación, idioma y, opcionalmente, el método de pago seleccionado, el correo electrónico del cliente, el nombre del cliente y los datos de los campos del formulario.
    - Durante el registro del webhook: la Gateway Key y la URL de callback de este sitio, para que ifthenpay pueda notificar directamente al sitio cuando se resuelva un pago.
    - Durante las solicitudes de activación de método de pago: cuando un administrador solicita la activación de un nuevo método de pago desde `WPForms → Ajustes → Pagos`, se envía un correo electrónico al soporte de ifthenpay (suporte@ifthenpay.com) que contiene la Backoffice Key, la Gateway Key, el método de pago solicitado, la dirección de correo del administrador, la URL del sitio, el nombre del sitio, la versión de WordPress, la versión de WPForms y la versión del plugin.
    - Durante los callbacks: Estado del pago y método de pago.
  - **Acuerdo de Licencia de Usuario Final (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Política de Privacidad**: [Política de Privacidad](https://ifthenpay.com/politica-de-privacidade/)

Todas las solicitudes de red se realizan del lado del servidor mediante HTTPS. Las credenciales sensibles se almacenan de forma segura y no se exponen públicamente. No se almacenan datos en bruto de tarjetas ni bancarios.

<a name="es-capturas"></a>
### Capturas de Pantalla

A continuación se muestran capturas de pantalla que demuestran las características e interfaces clave del plugin:

1. **(Solo Admin) Sincronización del Backoffice en WPForms Settings Payments**
   ![Ajustes del Backoffice](.wordpress-org/screenshot-1.png)
2. **(Solo Admin) Página de administración de WPForms (Creación/Edición de Formulario -> Pagos)**
   ![Ajustes de la Pasarela](.wordpress-org/screenshot-2.png)
3. **(Solo Admin) Añadir el campo de Pago de ifthenpay al formulario seleccionado**
   ![Añadir Campo al Formulario](.wordpress-org/screenshot-3.png)
4. **(Experiencia del Cliente) La visualización del campo de Pasarela de Pago varía según los ajustes de WPForms**
   ![Visualización del Campo](.wordpress-org/screenshot-4.png)
5. **(Experiencia del Cliente) Página de Pago Segura de ifthenpay**
   ![Página de Pago](.wordpress-org/screenshot-5.png)
6. **(Experiencia del Cliente) Mensaje de Pago (pagado, pendiente, cancelado o fallido)**
   ![Visualización del Mensaje de Pago](.wordpress-org/screenshot-6.png)
7. **(Solo Admin) Detalles del Pago**
   ![Detalles del Pago](.wordpress-org/screenshot-7.png)
8. **(Solo Admin) Entradas de Pago**
   ![Entradas de Pago](.wordpress-org/screenshot-8.png)

<a name="es-soporte"></a>
### Soporte

Para asistencia, utilice el [foro de soporte de WordPress.org](https://wordpress.org/support):

Comprobaciones previas:

- Método de pago habilitado en la Clave de Pasarela Y asignado a la Integración
- Ejecutar las versiones actuales recomendadas de WordPress, PHP y WPForms

Helpdesk comercial disponible (sin necesidad de correo directo): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **Soporte de ifthenpay**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **Documentación de WPForms**: [WPForms docs](https://wpforms.com/docs/)

[⬆ Cambiar idioma / Change language](#user-content-top)

</details>

<details name="lang">
<summary><strong>🇫🇷 Français</strong></summary>

<a name="fr"></a>

## Table des Matières

- [Description](#user-content-fr-description)
- [Fonctionnalités Principales](#user-content-fr-fonctionnalites)
- [Prérequis](#user-content-fr-prerequis)
- [Installation](#user-content-fr-installation)
- [Utiliser ifthenpay Avec une Autre Passerelle de Paiement](#user-content-fr-utiliser-autre-passerelle)
- [Messages de Confirmation de la Popup](#user-content-fr-messages-confirmation-popup)
- [Questions Fréquentes](#user-content-fr-faq)
- [Services Externes](#user-content-fr-services-externes)
- [Captures d'Écran](#user-content-fr-captures)
- [Support](#user-content-fr-support)

<a name="fr-description"></a>
### Description

Ce plugin intègre la passerelle de paiement ifthenpay à WPForms afin de permettre la collecte de paiements directement depuis vos formulaires. Les paiements sont traités via un système sécurisé de pay-by-link, garantissant qu'aucune donnée sensible de carte ou donnée bancaire n'est stockée sur votre site web. Les clients peuvent effectuer le paiement avec la méthode de leur choix via une page de paiement sécurisée. Après l'envoi d'un formulaire, les utilisateurs sont redirigés vers la page de paiement sécurisée hébergée par ifthenpay pour finaliser la transaction ; ifthenpay envoie ensuite une notification (callback) côté serveur pour mettre à jour automatiquement le statut du paiement.

**En termes simples, vous obtenez :**

- Des paiements uniques directement depuis WPForms
- La prise en charge des coupons et le calcul automatique des totaux
- Un backoffice commerçant (ventes de base) sur le web et sur mobile
- Des confirmations de paiement automatiques et sécurisées (aucun numéro de carte stocké)

Tous les réglages se font dans WPForms et dans votre Backoffice ifthenpay. Le plugin est conçu pour que les propriétaires de sites puissent gérer les paiements sans connaissances techniques approfondies.

<a name="fr-fonctionnalites"></a>
### Fonctionnalités Principales

1. Intégration complète avec le flux de paiement de WPForms Lite et Pro
2. Transactions sécurisées
3. Confirmation automatique du paiement
4. Prise en charge de plusieurs méthodes de paiement (cartes, portefeuilles électroniques, virements)
5. Prise en charge des coupons et remises via WPForms
6. Redirection sécurisée en pleine page vers la page de paiement hébergée par ifthenpay
7. Statut du paiement en temps réel dans les entrées WPForms
8. Prise en charge multilingue (EN, ES, FR, PT)
9. Sécurité avant tout (aucune donnée de carte stockée)

<a name="fr-prerequis"></a>
### Prérequis

- Un compte marchand ifthenpay actif — [inscrivez-vous ici](https://ifthenpay.com/aderir/) pour obtenir vos identifiants.
- Une Clé de Passerelle WPForms (à demander au support/helpdesk d'ifthenpay).
- Les méthodes de paiement que vous souhaitez activer sur cette Clé de Passerelle (notre équipe de helpdesk vous guidera).
- WordPress 6.5+ et PHP 8.2+, avec WPForms installé et activé.
- HTTPS (SSL) activé sur votre site.

<a name="fr-installation"></a>
### Installation

1. **Installer :** Téléversez le fichier zip du plugin via `Extensions → Ajouter → Téléverser`, ou installez-le depuis WordPress.org, puis activez-le.
2. **Identifiants :** Assurez-vous que votre compte ifthenpay dispose d'une Clé de Passerelle WPForms active avec les méthodes de paiement souhaitées activées.
3. **Configuration :** Allez dans `WPForms → Réglages → Paiements` et saisissez votre Backoffice Key.
4. **Configuration du formulaire :** `Créer/Modifier un formulaire → onglet Paiements → Ajouter le champ Ifthenpay à votre formulaire → activer « ifthenpay | Payment Gateway »` et sélectionnez une Clé de Passerelle. Choisissez ensuite les méthodes de paiement à activer parmi celles disponibles sur votre passerelle, puis définissez votre méthode de paiement par défaut. Enfin, ajoutez une description de paiement, qui sera affichée sur la page de paiement ifthenpay pour toutes les transactions.

<a name="fr-utiliser-autre-passerelle"></a>
### Utiliser ifthenpay Avec une Autre Passerelle de Paiement

WPForms n'autorise qu'une seule méthode de paiement active par soumission. Si un formulaire présente à la fois le champ ifthenpay et le champ d'une autre passerelle de paiement (PayPal, Stripe, Square ou Authorize.Net) visibles en même temps, le champ ifthenpay se masque automatiquement — logo, méthodes de paiement et bouton « Payer maintenant » — afin que les clients ne voient jamais qu'une seule option de paiement réellement fonctionnelle, plutôt qu'un bouton qui échouerait ou les débiterait deux fois.

- Le champ ifthenpay réapparaît automatiquement si le champ de l'autre passerelle redevient masqué (par exemple via la logique conditionnelle de WPForms), sans nécessiter de rechargement de la page.
- Cela ne s'applique qu'aux champs de passerelle réellement visibles sur le formulaire. Un champ de passerelle présent mais masqué par une logique conditionnelle ne déclenche pas ce comportement.
- Les champs natifs de WPForms tels que Total, Coupon et les éléments de paiement à choix unique/multiple/case à cocher/liste déroulante ne sont jamais considérés comme des passerelles concurrentes — seuls les champs PayPal, Stripe, Square et Authorize.Net le sont.

<a name="fr-messages-confirmation-popup"></a>
### Messages de Confirmation de la Popup

Chaque formulaire avec ifthenpay activé dispose de ses propres **Messages de Confirmation de la Popup**, configurés dans `Créer/Modifier un formulaire → onglet Paiements`. Ces messages contrôlent ce que les clients voient dans la popup de paiement ifthenpay pour chaque résultat, et sont indépendants de l'onglet Confirmations natif de WPForms, qui n'affecte pas cette popup.

- Personnalisez le message affiché pour chacun des quatre résultats : **Payé**, **En attente**, **Échoué** et **Annulé**. Chacun démarre avec un texte par défaut pertinent, que vous pouvez modifier ou conserver tel quel.
- Pour le résultat **Payé** uniquement, choisissez un Type de Confirmation : afficher un **Message** dans la popup, rediriger vers une **Page** WPForms, ou rediriger vers une **URL de Redirection** — avec la même flexibilité que les confirmations natives de WPForms.
- Activez éventuellement **Afficher un aperçu de l'entrée après le message de confirmation** sur le résultat Payé, afin que les clients voient un résumé de leur entrée soumise juste après le message de confirmation du paiement.

<a name="fr-faq"></a>
### Questions Fréquentes

<details>
<summary><strong>Ce plugin nécessite-t-il WPForms ?</strong></summary>
Oui. WPForms doit être installé et actif pour utiliser ce plugin.
</details>

<details>
<summary><strong>Prend-il en charge les paiements récurrents ?</strong></summary>
Non. Cette version ne prend en charge que les paiements uniques via pay-by-link.
</details>

<details>
<summary><strong>Les données de paiement sont-elles stockées ?</strong></summary>
Non. Le plugin ne stocke pas les numéros de carte ni les coordonnées bancaires complètes. Seules les références minimales nécessaires au rapprochement du paiement sont conservées.
</details>

<details>
<summary><strong>Prend-il en charge les coupons WPForms ?</strong></summary>
Oui. Les champs de coupon WPForms sont entièrement pris en charge et les remises sont calculées automatiquement.
</details>

<details>
<summary><strong>Quelles méthodes de paiement sont prises en charge ?</strong></summary>
Toute méthode ifthenpay associée à votre Clé de Passerelle (par exemple, Multibanco, MB WAY, Payshop, Carte de Crédit, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>Comment fonctionne le processus de paiement ?</strong></summary>
Après l'envoi du formulaire, les utilisateurs sont redirigés vers une page de paiement sécurisée hébergée par ifthenpay. Une fois le paiement effectué, le statut est mis à jour automatiquement via un callback.
</details>

<details>
<summary><strong>Que se passe-t-il en cas d'échec d'un paiement ?</strong></summary>
L'entrée est marquée comme Échouée. Les utilisateurs peuvent retenter le paiement selon votre configuration.
</details>

<details>
<summary><strong>Puis-je personnaliser l'expérience de paiement ?</strong></summary>
Oui. Vous pouvez configurer le libellé du bouton, la description et le style directement dans WPForms, ainsi qu'un Message de Confirmation de la Popup distinct pour chaque résultat de paiement (Payé, En attente, Échoué, Annulé). Voir <a href="#user-content-fr-messages-confirmation-popup">Messages de Confirmation de la Popup</a>.
</details>

<details>
<summary><strong>Que se passe-t-il si mon formulaire contient aussi un autre champ de passerelle de paiement (PayPal, Stripe, Square, Authorize.Net) ?</strong></summary>
Le champ ifthenpay se masque automatiquement tant que le champ de l'autre passerelle est visible, afin que les clients ne voient jamais deux options de paiement actives en même temps. Voir <a href="#user-content-fr-utiliser-autre-passerelle">Utiliser ifthenpay Avec une Autre Passerelle de Paiement</a>.
</details>

<details>
<summary><strong>Existe-t-il un environnement de test (sandbox) ?</strong></summary>
ifthenpay peut fournir des entités de test ; si ce n'est pas possible, effectuez un test réel de faible montant.
</details>

<details>
<summary><strong>Quel est le niveau de sécurité de l'intégration ?</strong></summary>
Les requêtes sont chiffrées via HTTPS ; aucune donnée de paiement sensible n'est stockée.
</details>

<details>
<summary><strong>Le module complémentaire ifthenpay pour WPForms accepte-t-il les WEBHOOKS (Callbacks) ?</strong></summary>
Oui ! Le module complémentaire ifthenpay pour WPForms, à partir de la version 2.0.0 et des suivantes, accepte les webhooks (callbacks).
</details>

<a name="fr-services-externes"></a>
### Services Externes

Ce plugin s'intègre à la plateforme de paiement ifthenpay pour traiter les paiements des soumissions WPForms. ifthenpay est un service tiers qui fournit un traitement sécurisé des paiements par carte, portefeuille électronique et virement bancaire local.

- **WPForms**
  - **Ce que c'est et à quoi ça sert** : Un plugin de création de formulaires utilisé pour créer des formulaires de paiement. Ce plugin étend ses capacités de paiement.

- **Backoffice et Intégrations ifthenpay**
  - **Ce que c'est et à quoi ça sert** : Le Backoffice ifthenpay est le tableau de bord marchand utilisé pour gérer les intégrations et les configurations de paiement. Le plugin utilise l'API ifthenpay pour générer des liens de paiement et valider les transactions.
  - **Quelles données sont envoyées et quand** :
    - Lors de la configuration : Backoffice Key et Gateway Key pour l'authentification et la récupération de la configuration.
    - Lors du traitement du paiement : identifiant de référence de commande, montant, description, comptes des méthodes de paiement activées, URL de retour succès/erreur/annulation, langue et, éventuellement, la méthode de paiement sélectionnée, l'e-mail du client, le nom du client et les données des champs du formulaire.
    - Lors de l'enregistrement du webhook : la Gateway Key et l'URL de callback de ce site, afin qu'ifthenpay puisse notifier directement le site lorsqu'un paiement est finalisé.
    - Lors des demandes d'activation d'une méthode de paiement : lorsqu'un administrateur demande l'activation d'une nouvelle méthode de paiement depuis `WPForms → Réglages → Paiements`, un e-mail est envoyé au support ifthenpay (suporte@ifthenpay.com) contenant la Backoffice Key, la Gateway Key, la méthode de paiement demandée, l'adresse e-mail de l'administrateur, l'URL du site, le nom du site, la version de WordPress, la version de WPForms et la version du plugin.
    - Lors des callbacks : statut du paiement et méthode de paiement.
  - **Contrat de Licence Utilisateur Final (CLUF/EULA)** : [EULA](https://ifthenpay.com/eula/)
  - **Politique de Confidentialité** : [Politique de Confidentialité](https://ifthenpay.com/politica-de-privacidade/)

Toutes les requêtes réseau sont effectuées côté serveur via HTTPS. Les identifiants sensibles sont stockés en toute sécurité et ne sont pas exposés publiquement. Aucune donnée brute de carte ou bancaire n'est stockée.

<a name="fr-captures"></a>
### Captures d'Écran

Voici des captures d'écran illustrant les principales fonctionnalités et interfaces du plugin :

1. **(Admin uniquement) Synchronisation du Backoffice dans WPForms Settings Payments**
   ![Réglages du Backoffice](.wordpress-org/screenshot-1.png)
2. **(Admin uniquement) Page d'administration de WPForms (Création/Modification de Formulaire -> Paiements)**
   ![Réglages de la Passerelle](.wordpress-org/screenshot-2.png)
3. **(Admin uniquement) Ajout du champ de Paiement ifthenpay au formulaire sélectionné**
   ![Ajout du Champ au Formulaire](.wordpress-org/screenshot-3.png)
4. **(Expérience Client) L'affichage du champ Passerelle de Paiement varie selon les réglages de WPForms**
   ![Affichage du Champ](.wordpress-org/screenshot-4.png)
5. **(Expérience Client) Page de Paiement Sécurisée ifthenpay**
   ![Page de Paiement](.wordpress-org/screenshot-5.png)
6. **(Expérience Client) Message de Paiement (payé, en attente, annulé ou échoué)**
   ![Affichage du Message de Paiement](.wordpress-org/screenshot-6.png)
7. **(Admin uniquement) Détails du Paiement**
   ![Détails du Paiement](.wordpress-org/screenshot-7.png)
8. **(Admin uniquement) Entrées de Paiement**
   ![Entrées de Paiement](.wordpress-org/screenshot-8.png)

<a name="fr-support"></a>
### Support

Pour obtenir de l'aide, utilisez le [forum de support WordPress.org](https://wordpress.org/support) :

Vérifications préalables :

- Méthode de paiement activée sur la Clé de Passerelle ET associée à l'Intégration
- Exécution des versions actuelles recommandées de WordPress, PHP et WPForms

Helpdesk commercial disponible (aucun e-mail direct requis) : [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **Support ifthenpay** : [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **Documentation WPForms** : [WPForms docs](https://wpforms.com/docs/)

[⬆ Changer de langue / Change language](#user-content-top)

</details>
