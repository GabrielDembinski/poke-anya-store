# Publicar a PokeAnya Store

O provedor escolhido é a **Hostinger**, ainda sem plano contratado. Comece por [PUBLICAR-HOSTINGER.md](PUBLICAR-HOSTINGER.md): o roteiro inclui a mensagem para confirmar os requisitos com o suporte antes da compra. Este documento continua como referência geral de instalação e migração.

A loja tem entrada livre para os clientes e um painel protegido para o administrador. O conteúdo comercial fica compartilhado no servidor. Para disponibilizá-la na internet, ainda é necessário confirmar um plano compatível, configurar um endereço HTTPS e migrar os arquivos e dados.

O acesso administrativo não tem custo de um serviço de login. A versão atual não depende de cadastro de clientes ou envio de e-mail. A hospedagem e o endereço público são uma decisão separada; nenhuma publicação ou contratação foi feita automaticamente.

Para colocar a loja no ar, falta confirmar uma hospedagem compatível, obter um endereço HTTPS, enviar o pacote, restaurar os bancos privados e conferir o funcionamento em dois aparelhos. Um subdomínio gratuito pode ser suficiente para o endereço; o serviço precisa permitir o uso comercial e conservar os dados desta loja.

## 1. Confirmar os requisitos da hospedagem

Este roteiro utiliza:

- PHP **8.2 ou mais recente**, com **PDO SQLite**.
- Um servidor compatível com as regras do `.htaccess` fornecido. O roteiro original usa Apache **2.4** com `mod_rewrite`; na Hostinger, é necessário validar essas regras no LiteSpeed do plano escolhido, conforme o guia específico.
- HTTPS válido para a origem pública.
- Uma pasta privada persistente, fora dos arquivos públicos, gravável pelo PHP.
- Uma única instalação que usa as mesmas bases SQLite e conserva os arquivos entre reinícios e atualizações.

Uma hospedagem apenas estática não executa esta API. O armazenamento atual é apropriado para uma instalação PHP com dados persistentes; consulte a [orientação oficial do SQLite](https://www.sqlite.org/whentouse.html) ao avaliar carga e concorrência.

O endereço pode ser um subdomínio gratuito fornecido pela hospedagem, se o plano permitir esta loja comercial e atender aos requisitos. Não é necessário verificar um domínio para envio de e-mails nesta versão.

## 2. Preparar os dados locais

1. Execute **BANCO-DE-DADOS.bat**. Inicializar cria o que falta e preserva os registros existentes.
2. No navegador com os dados antigos, entre no painel e use **Migrar dados deste navegador**. Confira o backup e a confirmação, pois a restauração substitui catálogo, pedidos e movimentações da base central.
3. Use **Migrar avaliações anteriores** para os comentários antigos. Eles ficam identificados como histórico.
4. Confira produtos, imagens, pedidos e saldos no servidor local.
5. Pause as alterações e execute **BACKUP-BANCO.bat**. Guarde a pasta privada e o manifesto informados.

O backup inclui `admins.sqlite`, `commerce.sqlite` e `customers.sqlite`, quando existentes. O último preserva cadastros de clientes da etapa anterior, sem ativá-los como acesso público. As imagens enviadas ficam na base comercial.

O hash da senha administrativa continua válido quando sua base é migrada. Não é necessário conhecer a senha em texto para copiar a base. Não copie um SQLite em uso ignorando seus arquivos WAL; use as cópias consistentes do projeto. Sessões abertas não precisam ser transportadas.

## 3. Enviar os arquivos públicos

Um exemplo de organização:

```text
/home/CONTA/public_html/                    código público da loja
/home/CONTA/pokeanya-private/auth.config.php configuração privada
/home/CONTA/pokeanya-private/data/           bases e sessões privadas
```

Use os caminhos absolutos reais fornecidos pela hospedagem.

O pacote `release/PokeAnya-Store.zip` reúne o código e os recursos públicos. Extraia seu conteúdo diretamente na raiz pública, incluindo o `.htaccess`. Para gerar novamente, execute `python tools/package-release.py`; `release/manifest.json` registra as somas SHA-256.

O pacote não inclui contas, bases, sessões, backups ou configurações privadas. Não coloque esses dados dentro de `public_html`. Testes e ferramentas Windows `.bat` não precisam ser publicados.

O roteador e o `.htaccess` preservam a verificação administrativa. As regras usam a opção `[END]` do Apache 2.4, descrita na [documentação oficial](https://httpd.apache.org/docs/2.4/rewrite/flags.html#flag_end).

## 4. Criar a configuração privada

Copie o modelo `server/auth.config.example.php` ou `deploy/auth.production.example.php` para uma pasta privada fora do projeto público. Os valores necessários à instalação são:

```php
<?php
return [
    'app_env' => 'production',
    'base_url' => 'https://SEU-ENDERECO-PUBLICO',
    'data_dir' => '/home/CONTA/pokeanya-private/data',
];
```

Substitua os exemplos pelos valores reais. `base_url` é a origem exata HTTPS, sem caminho de subpasta ou barra final. O endereço pode ser um subdomínio aceito pela hospedagem.

Defina **`POKEANYA_AUTH_CONFIG`** no ambiente PHP com o caminho absoluto desse arquivo. Em um Apache com `mod_env` e essa diretiva autorizada, é possível declarar somente o caminho:

```apache
SetEnv POKEANYA_AUTH_CONFIG /home/CONTA/pokeanya-private/auth.config.php
```

O suporte da hospedagem deve confirmar como fornecer esse valor ao PHP. `POKEANYA_BASE_URL` e `POKEANYA_AUTH_DATA_DIR`, quando definidos no ambiente, prevalecem sobre os valores do arquivo.

Permita leitura da configuração e escrita da pasta de dados ao usuário PHP, mantendo-os fora do acesso público. Configurações antigas podem permanecer arquivadas privadamente, mas credenciais de serviços externos de login ou e-mail não são necessárias à versão atual.

## 5. Restaurar e inicializar

Com a instalação ainda fora de uso público:

1. Coloque as cópias atuais das bases na pasta `data_dir`, preservando os nomes.
2. Não substitua dados de uma instalação que já recebe pedidos novos sem conciliar a restauração.
3. No terminal da hospedagem, com a configuração privada disponível ao PHP CLI, execute:

   ```sh
   php server/database.php init
   php server/database.php status
   ```

4. Confira os arquivos e permissões antes de abrir a loja. Se não houver terminal, confirme com o suporte como executar a manutenção; não publique uma rota aberta de criação ou recuperação de administrador.

O inicializador preserva os registros existentes. O administrador continua com o mesmo e-mail e senha após a restauração de sua base atual. Em uma instalação nova, crie o acesso com o configurador local e leve essa base privada.

## 6. Ativar o endereço HTTPS

Cadastre o domínio ou subdomínio na hospedagem e aplique os registros de endereço fornecidos por ela. Ative o certificado e o redirecionamento HTTP para HTTPS no painel.

Confira que o endereço aberto coincide com `base_url`. Os cookies de produção usam conexão segura; a sessão e a API precisam trabalhar na mesma origem. Um endereço só acessível no computador local não permite que os clientes usem a loja pela internet.

## 7. Conferir antes de divulgar

Use dois navegadores ou aparelhos:

1. Abra o catálogo sem login. Não deve existir exigência de cadastro para navegar, preparar pedidos ou avaliar produtos.
2. Entre no painel, altere um produto e atualize a loja no outro aparelho. As fotos, os preços e o estoque devem vir da mesma instalação.
3. Prepare pedidos com estoque e sob encomenda. Confira-os no painel e aprove uma venda uma única vez. O saldo deve diminuir apenas na aprovação.
4. Recarregue o recibo do pedido na sessão que o criou. Outros visitantes não devem receber a lista de compradores ou contatos.
5. Publique uma avaliação informando um nome e confira-a no outro navegador. O outro visitante não deve poder editar ou excluir o comentário por conhecer seu nome.
6. Troque a senha administrativa e confira a nova entrada e a revogação das outras sessões administrativas.
7. Sem sessão administrativa, tente abrir `/admin.html` e alterar produtos. O painel deve pedir senha e a API deve recusar a gravação.
8. Execute um backup privado do servidor e guarde uma cópia em local separado da hospedagem.

Confira também os dados reais de contato e atendimento. O fluxo de pedidos continua com confirmação da loja e WhatsApp; esta publicação não adiciona cobrança on-line, frete ou compra automática de fornecedores.

## Manter a loja

Faça backups antes de atualizar código ou restaurar dados. Use `php server/database.php backup` com a configuração privada correspondente para uma cópia consistente do servidor.

O procedimento de recuperação de senha do proprietário precisa atuar na base atual da instalação, conforme [SENHAS.md](SENHAS.md). Alterar apenas a cópia local não troca a senha do servidor.

Se forem necessárias várias instâncias em máquinas diferentes ou concorrência maior, o armazenamento precisará de uma migração compatível com esse modelo. Nesta etapa, todos compartilham os dados porque o PHP usa uma única instalação persistente.
