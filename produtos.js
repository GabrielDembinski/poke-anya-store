/* Semente inicial opcional. O cadastro diário agora é feito em admin.html.
   Esta lista só é usada antes da primeira gravação ou restauração no navegador.
   Preencha apenas quando a loja confirmar fotos, preços e estoque.
   Mantenha um id único em cada produto. Categorias: cartas, figures, chaveiros,
   pelucias, albuns, roupas. Imagens devem estar na pasta assets.
   Exemplo de estrutura (não exibido):
   { id: 'produto-01', nome: 'Nome confirmado', categoria: 'cartas',
     preco: 49.90, imagem: 'assets/produto-01.jpg', descricao: 'Detalhes do item', estoque: 0 }
   preco: null exibe "Consulte o valor". Não use dados de clientes neste arquivo.
*/
window.PRODUTOS = [];
