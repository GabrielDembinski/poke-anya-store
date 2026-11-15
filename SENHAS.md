# Senha do administrador

Nesta versão, somente o administrador utiliza senha. Clientes entram livremente para consultar produtos, preparar pedidos e deixar avaliações.

A autenticação e a troca da senha administrativa funcionam no próprio servidor. Não há recuperação por e-mail, remetente transacional ou cadastro público de administrador.

## Alterar com a senha atual

1. Entre no painel administrativo.
2. Escolha **Alterar senha**.
3. Informe a senha atual, a nova senha e a confirmação.
4. Use de **12 a 128 caracteres** e guarde a nova senha em local seguro.

A sessão atual permanece conectada e as outras sessões administrativas são revogadas. O e-mail usado para entrar continua sendo o identificador da conta. Nenhuma mensagem precisa ser enviada para concluir essa troca.

## Recuperar uma senha esquecida

O responsável com acesso aos arquivos e à base privada utiliza **RECUPERAR-ADMIN.bat**. Esse procedimento não é oferecido a visitantes por uma rota pública.

1. Na instalação local responsável pelos dados, abra **RECUPERAR-ADMIN.bat**.
2. Confirme a operação digitando **REDEFINIR**, conforme o configurador solicitar.
3. Informe o mesmo e-mail administrativo usado para entrar no painel.
4. Escolha a nova senha de 12 a 128 caracteres e confirme-a. A digitação fica protegida no terminal.
5. Entre no painel com a nova senha.

A ferramenta preserva o nome, o e-mail e a conta administrativa existente, altera somente a senha e revoga as sessões anteriores. Ela não cria um segundo administrador nem envia e-mail. Somente quem já possui acesso ao ambiente privado do servidor deve executar essa recuperação.

Em uma instalação publicada, o procedimento precisa atuar na base atual da hospedagem. Alterar a cópia local do computador não muda automaticamente o servidor. Não substitua a base publicada por uma cópia antiga para tentar trocar a senha.

O script correspondente é `server/reset-admin.php`, executável apenas pelo PHP de linha de comando. O suporte ou responsável pelo servidor deve utilizar esse procedimento no ambiente atual da loja, com a configuração privada correta. Não coloque senhas em argumentos de comandos, arquivos públicos ou mensagens de suporte; os dados são fornecidos pela entrada do processo.

## Preservação e publicação

`admins.sqlite` guarda a conta administrativa e o hash da senha. `BACKUP-BANCO.bat` inclui essa base no backup privado. Conserve esses arquivos fora da pasta pública.

O administrador usa a mesma senha após uma migração que preserve sua base atual. Os registros antigos de clientes podem continuar arquivados privadamente para uma futura etapa, sem criar obrigação de login para os visitantes.

Consulte [ADMINISTRADOR.md](ADMINISTRADOR.md) para o primeiro acesso e [PUBLICAR-LOJA.md](PUBLICAR-LOJA.md) para disponibilizar a loja. Não compartilhe senhas, arquivos de sessão ou configuração privada pelo chat.
