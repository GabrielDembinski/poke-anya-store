# PokeAnya Store

Loja com entrada livre para os clientes e conteúdo compartilhado no servidor PHP. Apenas o administrador precisa entrar com e-mail e senha para cuidar dos produtos, pedidos e estoque.

O catálogo, os pedidos e as avaliações não exigem cadastro de cliente. A autenticação administrativa é implementada pelo próprio projeto e não depende de um serviço externo de login ou de envio de e-mail.

## Abrir e testar localmente

1. Instale PHP 8.2 ou mais recente, com PDO SQLite, disponível no PATH.
2. Abra `INICIAR-LOJA.bat` e mantenha a janela aberta. Os bancos são inicializados sem apagar registros existentes.
3. Acesse `http://127.0.0.1:8000` para a loja e `http://127.0.0.1:8000/admin.html` para o painel.
4. No primeiro acesso administrativo, use `CONFIGURAR-ADMIN.bat` para definir nome, e-mail de acesso e senha. Não existe senha padrão.

O Live Server, um servidor apenas estático e abrir HTML diretamente não executam a API. Com as extensões já habilitadas, também é possível executar `php -S 127.0.0.1:8000 server/router.php`. Esse servidor é para desenvolvimento; a publicação está em [PUBLICAR-LOJA.md](PUBLICAR-LOJA.md).

## Acesso livre e administrador

Clientes podem explorar categorias, preparar pedidos e deixar avaliações sem entrar em uma conta. Nome e contato solicitados no pedido servem para a loja atender aquela solicitação; não criam um cadastro de login.

O administrador usa uma conta única, separada dos visitantes. O e-mail identifica esse acesso e não exige contratar uma caixa de e-mail. O servidor verifica a sessão e a autorização em cada alteração administrativa.

A troca de senha exige a senha atual. A recuperação do acesso é um procedimento local do responsável pelo servidor, explicado em [SENHAS.md](SENHAS.md); não há envio de links por e-mail nem recuperação administrativa pública.

Os cadastros antigos de clientes permanecem guardados na base privada para preservação dos dados. Eles não habilitam login público nesta versão.

## Migrar os dados da versão anterior

Os registros antigos do navegador são preservados. Eles não são enviados nem apagados automaticamente.

1. No navegador com os produtos e pedidos antigos, entre no painel.
2. Confira um backup antes de migrar e use **Migrar dados deste navegador** quando o aviso aparecer.
3. Leia a confirmação: a operação restaura catálogo, pedidos e movimentações, substituindo os registros correspondentes do servidor.
4. Use **Migrar avaliações anteriores** para levar o histórico local. Esses comentários são identificados como histórico importado.
5. Confira categorias, fotos, preços, pedidos e saldos em outro navegador.

Faça a migração inicial antes de divulgar a loja. Se já existirem registros novos no servidor, faça backup e confira a conciliação antes de substituí-los. As chaves antigas permanecem no navegador; um marcador evita repetir o aviso para a mesma origem migrada. Pedidos antigos sem recibo privado permanecem no histórico administrativo, sem expor contatos publicamente.

## Produtos e imagens

O administrador informa nome, categoria, imagem, preço, estoque e descrição. Arquivos enviados são otimizados no navegador, validados no servidor e armazenados no banco privado. URLs externas HTTP/HTTPS também são aceitas; em uma loja HTTPS, prefira imagens HTTPS ou enviadas pelo painel.

O produto aparece em **Tudo** e na categoria escolhida. O menu **⋯** permite editar ou remover. A remoção retira o item da vitrine e preserva os registros históricos. Estoque zero mantém o produto disponível **sob encomenda**.

As gravações aguardam a confirmação do servidor. Edições obsoletas são recusadas para evitar sobrescrever uma alteração mais recente.

## Pedidos e estoque

**Preparar meu pedido** grava uma solicitação pendente no servidor e a mostra no painel. **Enviar pedido no WhatsApp** abre a mensagem para o cliente enviar; o site não envia essa mensagem sozinho.

Preparar um pedido não reserva nem diminui o estoque. O administrador combina a venda e então a aprova. A baixa é feita em transação, retirando somente o disponível. Por exemplo: estoque 2, pedido 5, aprovação retira 2 e registra 3 sob encomenda. Repetir a aprovação não retira unidades novamente. Cancelar um pedido pendente não altera o saldo.

Preços e nomes dos itens vêm do servidor e ficam preservados no pedido. O catálogo público não fornece uma lista de compradores ou contatos. Cada pedido novo recebe um recibo privado para recuperação na sessão do navegador.

## Avaliações sem cadastro

O visitante informa seu nome, uma nota de 1 a 5 Pokébolas e um comentário. Esse nome é informado pela própria pessoa e não comprova identidade ou compra; não é um nome de usuário exclusivo.

O servidor identifica a sessão de visitante para permitir editar ou excluir o próprio comentário neste navegador. Outro visitante não recebe essa permissão por conhecer o nome ou o identificador da avaliação. Trocar de navegador, limpar os dados ou deixar a sessão expirar pode retirar a opção de gerenciar uma avaliação anterior; o comentário continua preservado no servidor.

Avaliações antigas continuam legíveis. As importadas recebem identificação de histórico e não assumem a identidade de uma sessão nova. Remover um produto oculta seus comentários da vitrine e preserva o histórico no banco.

As páginas renovam o catálogo enquanto estão em uso, ao retomar a aba e em intervalos de até 30 segundos. Preferências de tema, rascunhos e listas ainda não enviadas podem continuar particulares do navegador.

## Aparência

Os temas **Identidade da loja**, **Claro** e **Escuro** preservam a paleta da marca. Instruções e mensagens usam textos maiores, com quebras para telas pequenas; títulos e subtítulos mantêm sua hierarquia.

O fundo neon, as fotos das categorias e os controles de animação permanecem. As preferências de movimento respeitam a configuração do dispositivo. A origem das imagens está em `assets/categories/LEIA-ME.md`.

## Bancos e backups

A raiz privada padrão no Windows é `%LOCALAPPDATA%\PokeAnyaStore\auth`, ou o caminho absoluto de `POKEANYA_AUTH_DATA_DIR`/configuração privada. Os dados ficam fora da pasta pública.

| Banco | Conteúdo |
| --- | --- |
| `admins.sqlite` | Administrador e autenticação administrativa |
| `commerce.sqlite` | Produtos, imagens, pedidos, estoque e avaliações |
| `customers.sqlite` | Cadastros antigos preservados; sem login público ativo |

`BANCO-DE-DADOS.bat` inicializa e confere a presença das bases. `BACKUP-BANCO.bat` cria cópias consistentes e um manifesto na pasta privada de backups. Não copie somente o arquivo principal de um SQLite em uso ignorando seus arquivos WAL.

O backup JSON do painel preserva o conteúdo comercial e não substitui o backup administrativo. Para conservar o acesso ao mudar de computador ou hospedagem, migre as bases privadas conforme o guia de publicação.

## Publicação e custo

O login do administrador não tem cobrança de um provedor de autenticação. Hospedagem, endereço público e HTTPS são uma etapa separada. Um subdomínio gratuito pode servir como endereço se a hospedagem permitir esta loja comercial e oferecer os recursos necessários.

[PUBLICAR-HOSTINGER.md](PUBLICAR-HOSTINGER.md) orienta a confirmação do plano escolhido e a instalação pelo hPanel. [PUBLICAR-LOJA.md](PUBLICAR-LOJA.md) descreve os requisitos gerais, a pasta persistente, a migração dos dados e a conferência em aparelhos diferentes. Não é necessário ativar e-mail transacional ou cadastrar remetentes para esta versão.

`release/PokeAnya-Store.zip` reúne código e recursos públicos, sem contas, sessões ou segredos. Gere novamente com `python tools/package-release.py` após alterar o projeto. A configuração privada é criada separadamente; há um modelo em `deploy/auth.production.example.php`.

A hospedagem escolhida é a Hostinger; o plano ainda não foi contratado. Confirme PDO SQLite, armazenamento privado e configuração do PHP com o suporte antes da compra. Gerar o pacote não publica a loja.

## Guardar o código no GitHub

Para este projeto de cliente, prefira um repositório **privado** e revise a lista de arquivos antes de publicar. O repositório deve guardar código, imagens públicas, documentação e exemplos de configuração. Os bancos privados e a configuração real permanecem fora dele.

O `.gitignore` exclui bancos, sessões, logs, configurações privadas, arquivos conhecidos de credenciais, chaves e backups exportados pelo painel. Esses backups podem conter pedidos e contatos de clientes. Mantenha `server/auth.config.example.php` e `deploy/auth.production.example.php`, que são modelos sem dados reais.

Git ou GitHub Desktop aplicam essas regras aos arquivos ainda não versionados. Confira os arquivos selecionados também em qualquer envio manual pelo navegador. [Como o GitHub orienta ignorar arquivos](https://docs.github.com/en/get-started/git-basics/ignoring-files).

O GitHub guarda as versões do código; a instalação desta loja continua no servidor PHP da Hostinger. O GitHub Pages é uma hospedagem estática e não executa a API PHP utilizada neste projeto. [Sobre o GitHub Pages](https://docs.github.com/en/pages/getting-started-with-github-pages/what-is-github-pages).

## Arquivos e verificação

`server/store-core.php` e `server/store-api.php` concentram os dados e as transações. `storage.js` e `reviews-store.js` mantêm caches de leitura; inicialização e gravações são assíncronas, sem fallback de escrita local. O acesso administrativo e a sessão de visitante são separados no servidor.

`server/database.php` oferece inicialização, status e backup. O procedimento de senha administrativa está em [SENHAS.md](SENHAS.md).

Os testes e comandos atuais ficam em [tests/README.md](tests/README.md). As antigas suítes de contas de clientes e armazenamento local descrevem etapas anteriores, não o acesso público desta versão. A validação local não substitui conferir HTTPS e o roteamento na hospedagem escolhida.
