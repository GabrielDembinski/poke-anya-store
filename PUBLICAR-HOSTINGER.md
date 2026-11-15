# Publicar a PokeAnya Store na Hostinger

**Roteiro preparado em 15 de novembro de 2026.** A hospedagem ainda não foi contratada, o domínio não está definido e este projeto ainda não foi validado na Hostinger. Confirme a compatibilidade antes de contratar. O login administrativo dispensa serviço externo de autenticação ou e-mail; a hospedagem tem condições comerciais próprias.

## 1. Confirmar o plano antes de contratar

Envie esta mensagem ao suporte da Hostinger:

> Quero publicar uma loja em PHP 8.2 ou superior, com banco SQLite em arquivos persistentes. Qual é o plano mais barato compatível? Preciso confirmar:
>
> - PDO SQLite habilitado no PHP do site e da linha de comando, com SQLite 3.35 ou superior.
> - Suporte a Argon2id em password_hash/password_verify, para preservar a senha administrativa já cadastrada.
> - Pasta privada fora de public_html, gravável pelo PHP e permitida pelo open_basedir, para bancos, sessões e configuração.
> - Como disponibilizar POKEANYA_AUTH_CONFIG ao PHP web e ao PHP CLI. A diretiva SetEnv do .htaccess funciona nesse ambiente?
> - Reescrita pelo .htaccess para server/router.php, incluindo páginas administrativas protegidas e cabeçalhos Authorization.
> - Acesso SSH/terminal para manutenção, HTTPS e disponibilidade de domínio temporário.
> - Preço inicial, duração da oferta, preço de renovação e eventuais custos do domínio.

Peça os caminhos privados absolutos permitidos. As extensões variam conforme a versão PHP; a tela de configuração não garante PDO SQLite em todo plano. [Configuração oficial de PHP](https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options-in-hostinger/).

Os próximos passos são posteriores à confirmação e contratação. Resolva requisitos ausentes antes de publicar; não mova os bancos para a pasta pública.

## 2. Criar o site PHP no hPanel

Em **Sites / Websites**, localize a hospedagem e escolha criar um site. Selecione **Custom PHP/HTML website**, opção para enviar o código existente. Escolha o domínio real ou o temporário oferecido pelo plano. O procedimento e as opções disponíveis dependem do produto contratado. [Adicionar um site](https://www.hostinger.com/support/1583214-how-to-add-a-website-in-hostinger/).

Em **Gerenciar site → Configuração PHP**, selecione PHP 8.2 ou superior e confirme PDO SQLite. Se não aparecer, peça ao suporte a confirmação no ambiente web e CLI; não é necessário criar usuário ou senha MySQL no phpMyAdmin, pois este projeto usa arquivos SQLite.

## 3. Preparar os dados e enviar o pacote

No computador, confira a migração dos dados antigos pelo painel, pause as alterações e execute **BACKUP-BANCO.bat**. Guarde as cópias consistentes e o manifesto; não copie apenas um SQLite em uso deixando os arquivos WAL para trás.

No hPanel, abra **Arquivos → Gerenciador de arquivos**. Envie `release/PokeAnya-Store.zip` e extraia em `public_html`. Confirme `index.html`, `server/`, `assets/` e `.htaccess` nessa raiz; remova o ZIP depois. O pacote não contém contas ou dados privados.

Na nova versão do Gerenciador, edições e arquivos novos aguardam **Deploy**, enquanto upload e extração são aplicados imediatamente. A atualização da ferramenta é gradual. Confira o estado mostrado pelo seu painel e revise antes de aplicar alterações. [Gerenciador oficial](https://www.hostinger.com/support/4548688-basic-actions-in-the-file-manager-in-hostinger/).

## 4. Criar a área privada

Use **Home / Todos os arquivos da hospedagem** para acessar os diretórios permitidos. Um exemplo, a substituir pelos caminhos confirmados pelo suporte:

```text
/home/u123/domains/SEU_DOMINIO/public_html/
/home/u123/domains/SEU_DOMINIO/pokeanya-private/auth.config.php
/home/u123/domains/SEU_DOMINIO/pokeanya-private/data/
```

Coloque `admins.sqlite` e `commerce.sqlite` do backup em `data/`. Isso preserva a senha administrativa, produtos, fotos e pedidos. `customers.sqlite`, se existente, pode ser preservado privadamente como legado; não ativa login de clientes.

Crie `auth.config.php` fora de `public_html`:

```php
<?php
return [
    'app_env' => 'production',
    'base_url' => 'https://SEU-ENDERECO-EXATO',
    'data_dir' => '/home/u123/domains/SEU_DOMINIO/pokeanya-private/data',
];
```

Troque todos os exemplos. A origem deve ser HTTPS, sem subpasta ou barra final. O PHP precisa receber `POKEANYA_AUTH_CONFIG` apontando para esse arquivo. Somente se o suporte confirmar essa forma para seu ambiente, acrescente a diretiva abaixo ao `.htaccess` fornecido em `public_html`, preservando todas as regras de proteção e roteamento existentes:

```apache
SetEnv POKEANYA_AUTH_CONFIG /home/u123/domains/SEU_DOMINIO/pokeanya-private/auth.config.php
```

Valide que o PHP web recebe o valor e consegue ler a pasta. Não presuma que LiteSpeed/LSAPI ou o `open_basedir` permitirão qualquer caminho. Permissões gerais de escrita para todos não resolvem essa configuração.

## 5. Inicializar e conferir

O terminal integrado usa SSH e depende de o plano incluir esse acesso e ele estar habilitado. Sem terminal, combine a manutenção com o suporte. [Terminal no Gerenciador](https://www.hostinger.com/support/how-to-use-the-terminal-in-hostinger-file-manager/).

Na pasta pública do projeto, usando o caminho privado real:

```sh
export POKEANYA_AUTH_CONFIG='/home/u123/domains/SEU_DOMINIO/pokeanya-private/auth.config.php'
php -v
php server/database.php init
php server/database.php status
```

O `export` vale para o terminal, não configura o PHP web. Confirme ambos separadamente. Se `php -v` indicar outra versão, peça o executável correto ao suporte. A inicialização preserva os registros existentes.

## 6. Ativar HTTPS e testar a loja

Nos planos Web/Cloud, a Hostinger inclui SSL; o site precisa estar adicionado e o domínio apontado e propagado para a instalação. Confira **Segurança → SSL** e o redirecionamento para HTTPS. [Orientação oficial de SSL](https://www.hostinger.com/support/1583258-how-to-install-lifetime-ssl-at-hostinger/).

Em dois aparelhos, confira:

- Catálogo, pedidos e avaliações sem login; produto alterado no painel aparece no outro aparelho.
- Pedido pendente chega ao administrador; estoque diminui somente na aprovação, inclusive encomendas parciais.
- WhatsApp abre a mensagem para o cliente enviar; o recibo privado continua acessível ao recarregar.
- `/admin.html` pede login sem sessão; operações privadas retornam erro de autorização e usam `no-store`, sem cache público.
- Login com a senha preservada e troca de senha funcionam; outro visitante não edita comentários alheios.

Antes de atualizar, execute `php server/database.php backup` com a configuração privada atual e guarde uma cópia fora da hospedagem. Recuperar a senha do administrador deve atuar na base hospedada, conforme [SENHAS.md](SENHAS.md); redefinir a cópia local não altera a loja publicada.
