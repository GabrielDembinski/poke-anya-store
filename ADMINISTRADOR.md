# Configurar e acessar o administrador

A loja é aberta aos visitantes. Apenas o administrador entra com e-mail e senha para alterar produtos, aprovar pedidos e cuidar do estoque.

Esse acesso é implementado no servidor do projeto. Não exige serviço externo de autenticação, caixa de e-mail paga ou remetente transacional.

## Primeiro acesso

1. Na pasta do projeto, abra **CONFIGURAR-ADMIN.bat**.
2. Informe nome e e-mail de acesso do responsável pela loja.
3. Escolha uma senha de **12 a 128 caracteres** e confirme. A digitação fica protegida no terminal.
4. Aguarde a confirmação e abra **INICIAR-LOJA.bat**.
5. Acesse `http://127.0.0.1:8000/admin.html` e informe os dados definidos.

O e-mail funciona como identificador de entrada; o sistema não precisa enviar uma mensagem para autenticar o administrador. O configurador não cria senha padrão e não substitui uma conta existente. O servidor guarda o hash da senha.

Use o servidor PHP. Live Server, servidores estáticos e arquivos abertos diretamente não executam a autenticação real. Mantenha o mesmo endereço e porta ao navegar; a origem deve coincidir com a configuração do servidor.

## Endereço reservado ao administrador

O rodapé e a navegação da loja não exibem um acesso administrativo. O responsável abre diretamente `http://127.0.0.1:8000/admin.html` durante os testes. Depois de publicar, use `https://SEU-ENDERECO/admin.html`. Salve esse endereço nos favoritos do celular ou do computador.

Quem abrir esse endereço sem uma sessão válida será encaminhado ao login. Conhecer o endereço não autoriza consultar pedidos nem alterar produtos: o servidor verifica a sessão em cada operação administrativa.

## Quando o sistema pede a senha novamente

- **Sair do painel** encerra imediatamente a sessão usada naquele navegador. O próximo acesso exige a senha.
- Depois de **30 minutos sem atividade administrativa**, o acesso expira. A atualização automática de pedidos não prolonga a sessão. Interações no painel, como tocar, digitar ou rolar com o mouse, renovam a atividade enquanto a sessão ainda é válida.
- O acesso tem um limite máximo de **8 horas**; depois disso, é necessário entrar novamente.
- Recarregar, trocar de aba ou abrir a vitrine não encerra uma sessão válida. Assim, o responsável pode trabalhar sem digitar a senha em cada tela.

O cookie administrativo é de sessão, mas alguns navegadores restauram esses cookies ao reabrir, como explica a [documentação do navegador sobre cookies de sessão](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie#expiresdate). Fechar uma aba ou o navegador não garante o encerramento. Ao terminar, principalmente em aparelho compartilhado, use **Sair do painel**. A verificação do servidor recusa ações após a expiração; o painel aberto verifica o acesso na próxima atualização ou ao voltar à tela.

As senhas ficam armazenadas como hash; há limite de tentativas de entrada, proteção contra solicitações indevidas e cookie separado dos visitantes. Em produção, use HTTPS e a configuração privada indicada em `PUBLICAR-LOJA.md`. Retirar o link da vitrine torna a navegação mais adequada ao cliente; a autorização continua sendo responsabilidade do servidor.

## Trocar a senha

No painel, **Alterar senha** abre o formulário protegido. Confirme a senha atual e escolha outra. A sessão utilizada permanece válida e as outras sessões administrativas são revogadas.

A operação não depende de envio de e-mail. Guarde o acesso com o responsável pela loja. Para recuperar uma senha esquecida pelo procedimento local do proprietário, siga [SENHAS.md](SENHAS.md).

O proprietário utiliza **RECUPERAR-ADMIN.bat**, informa o e-mail administrativo, confirma a redefinição e escolhe uma nova senha. Essa ferramenta atua na base privada da instalação e preserva o único administrador; não oferece uma rota pública de recuperação.

## Dados compartilhados

O painel lê e altera catálogo, pedidos, estoque e avaliações no servidor. Outros aparelhos conectados à mesma instalação consultam o mesmo conteúdo sem precisar de cadastro.

Aprovar uma venda retira somente as unidades disponíveis; o restante fica como encomenda. Preparar um pedido não baixa o estoque. O servidor exige autorização administrativa e impede aprovações repetidas.

Os pedidos preservam seus dados históricos. A lista pública de produtos não fornece nomes e contatos de compradores.

## Migrar os registros antigos

No navegador com os dados da versão anterior, entre no painel e confira os avisos **Migrar dados deste navegador** e **Migrar avaliações anteriores**.

A migração de catálogo, pedidos e movimentações é uma restauração explícita. Faça backup e leia a confirmação antes de substituir os registros no servidor. Prefira concluir a migração antes de receber pedidos públicos. Os dados antigos não são apagados automaticamente do navegador.

Avaliações antigas recebem identificação de histórico importado. Comentários de antigas contas continuam preservados, mas uma sessão de visitante nova não assume sua autoria. Recibos antigos sem token não são expostos para consulta pública; o histórico migrado permanece no painel.

## Bancos, cópias e publicação

A raiz privada padrão é `%LOCALAPPDATA%\PokeAnyaStore\auth`. A configuração privada pode definir outro caminho absoluto fora do site. O administrador usa `admins.sqlite` e o conteúdo comercial usa `commerce.sqlite`. `customers.sqlite` preserva os registros antigos, sem habilitar login público.

As sessões administrativas ficam em `admin-sessions` e usam o cookie `pokeanya_admin`. A identificação anônima dos visitantes usa uma sessão própria e não concede permissão administrativa.

**Exportar backup** no painel preserva o conteúdo comercial. **BACKUP-BANCO.bat** inclui também as bases privadas e um manifesto. Esses arquivos devem ficar fora da pasta pública e participar da migração para conservar a senha administrativa.

Siga [PUBLICAR-LOJA.md](PUBLICAR-LOJA.md) para escolher uma hospedagem compatível, instalar a loja em um endereço HTTPS e restaurar as bases. Cadastros de clientes e serviços de e-mail não são pré-requisitos desta versão.

## Conferir problemas

| Situação | O que conferir |
| --- | --- |
| Administrador ainda não configurado | Execute `CONFIGURAR-ADMIN.bat` no ambiente responsável pelos bancos. |
| Já existe um administrador | Use a conta existente; o configurador preserva esse registro. |
| E-mail ou senha inválidos | Confira os dados definidos para a administração. |
| “Não foi possível ler a configuração administrativa” | Feche o configurador antigo e abra `CONFIGURAR-ADMIN.bat` atualizado desta pasta. A versão corrigida envia os dados em UTF-8 sem o marcador invisível que impedia a leitura no Windows. |
| Senha esquecida | Siga o procedimento local de proprietário em `SENHAS.md`. |
| Serviço ou catálogo indisponível | Confira PHP, extensões, conexão e permissões da pasta privada; tente carregar novamente. |
| Dados diferentes em outro endereço | Confira se os aparelhos usam a mesma instalação e se os dados antigos foram migrados. |

Para pedir ajuda, informe a mensagem exibida. Não envie senhas, arquivos de sessão ou o conteúdo da configuração privada.
