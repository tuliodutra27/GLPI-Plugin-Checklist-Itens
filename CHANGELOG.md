# Changelog

Todas as mudanças relevantes deste plugin ficam registradas aqui.
O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e as versões
seguem o [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não lançado]

### Adicionado
- A situação do equipamento no GLPI pode acompanhar o bloqueio: uma situação para o equipamento
  bloqueado aguardando conferência, outra com o chamado aberto e outra ao ser liberado (ex.:
  Pendente, Manutenção e Ativo). Vale para o rádio e para o celular ou tablet, e só troca a
  situação que o próprio plugin deixou. Vem desligada: o TI escolhe as situações na configuração.
- Com o plugin Laudo, o chamado do equipamento bloqueado ganha um laudo inicial vinculado ao
  equipamento e ao chamado, com o relato do problema na descrição da ocorrência, o colaborador
  como usuário responsável e a data de início. O chamado recebe um acompanhamento privado com o
  link do laudo. A situação do laudo novo é configurável (padrão: "Aberto"; vazio = não cria).

### Corrigido
- Entrega para embarcação: o rádio que sai da entrega por problema deixava a entrega com "Rádios
  ok? Não". A resposta agora vale para os rádios que foram com a embarcação; o rádio que saiu
  continua aparecendo à parte, bloqueado.
- O link "Conferência do setor" do e-mail de equipamento não devolvido apontava sempre para a pasta
  `plugins/` e não abria com o plugin instalado pelo marketplace.
- A aba "Registro de uso" do Telefone podia continuar com o visual antigo depois de atualizar o
  plugin (o navegador não buscava o CSS novo).
- Na entrega e no recebimento da embarcação, os avisos das fotos (foto que não pôde ser lida, o que
  falta em cada bateria ou capa com defeito) apareciam sem o destaque de aviso.
- Foto ou selfie que o navegador não consegue reduzir e que não é JPEG nem PNG agora é avisada na
  hora, em vez de ser guardada e recusada pelo servidor ao salvar.
- Ao reabrir a tela da entrega ou do recebimento, a linha da localização mostra o mesmo texto da
  leitura original, com o motivo quando ela não foi registrada.
- Na configuração, o máximo de fotos por entrega ou recebimento aceitava mais de 200 no campo e
  voltava a 200 ao salvar sem aviso; agora o próprio campo avisa.

## [0.12.0] - 2026-11-10

Requer Atualizar em Configurar > Plugins.

O GLPI pede o Atualizar a cada versão nova. A estrutura do banco não muda: a atualização só grava
as duas opções novas da configuração.

### Adicionado
- Atalhos do plugin na página inicial do GLPI (interface simplificada), só com o que o perfil pode
  usar: Iniciar uso e Finalizar uso; Conferência do setor com o número de pendências; Entrega para
  embarcação e Receber da embarcação, com o aviso da separação ou do recebimento em andamento.
  Vêm desligados: o TI liga na configuração e escolhe os perfis que veem o bloco (padrão: Gestor
  Operacional).

## [0.11.0] - 2026-10-16

Requer Atualizar em Configurar > Plugins.

Testada no GLPI de teste como `0.11.0-rc.1` e `0.11.0-rc.2`, que não foram publicadas como
release.

### Adicionado
- Entrega de rádios para embarcação: o gestor separa vários rádios, as baterias reserva e as
  capas, fotografa, tira a selfie e entrega a uma embarcação cadastrada pelo TI.
- Recebimento de volta da embarcação no mesmo modelo, inclusive em partes, com fotos e selfie. As
  baterias e as capas devolvidas ficam limitadas às que foram entregues e ainda não voltaram.
- Baterias e capas com defeito no recebimento: cada uma tem pergunta própria ("estão todas
  boas?"). Com "Não", cada peça com defeito ganha um bloco em destaque com os problemas do catálogo
  e de 1 a 5 fotos, e ao concluir o plugin abre um chamado para o TI com tudo isso.
- Catálogo de problemas de bateria e de capa, editável pelo TI.
- "Rádios ok?" na entrega e no recebimento: com "Não", os rádios aparecem um a um logo abaixo da
  pergunta e o problema de cada um é marcado ali. Problema novo bloqueia o rádio; problema já
  conhecido (laudo em aberto) segue sem bloqueio.
- O que o gestor faz na entrega fica guardado no aparelho e é enviado em partes: a conexão pode
  cair no meio sem perder nada.
- Cadastro de embarcações, lista e detalhe das entregas com CSV, cancelamento da separação pelo TI
  e ação automática que cancela a separação parada além do prazo.

### Alterado
- A desinstalação não apaga mais as fotos do defeito nem as fotos das entregas; só as selfies.
- Rádios entregues a embarcações não passam pela conferência do setor nem pelo alerta de não
  devolvido.

### Corrigido
- O TI não conseguia criar nem excluir problemas do checklist.
- A resposta "Não" da devolução não era gravada; a atualização corrige as devoluções já
  registradas.

## [0.10.0] - 2026-10-03

Requer Atualizar em Configurar > Plugins.

### Adicionado
- Perfis padrão criados na instalação: Operador (interface simplificada) e Gestor Operacional.
  Perfis que já existem não são alterados.
- Fotos do equipamento com defeito: de 1 a 5 fotos, obrigatórias ao marcar um problema.
- Integração opcional com o plugin Laudo: o equipamento com laudo em aberto aparece com o selo
  "Problema conhecido" e o problema já conhecido não bloqueia o item de novo.
- Configuração da integração com o Laudo e das situações consideradas concluídas.

## Versões anteriores

As versões até a 0.9.0 (retirada e devolução com checklist, selfie com localização, conferência do
gestor por turno, bloqueio do item com problema e registro de uso) não foram publicadas como
release.

[Não lançado]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.12.0...HEAD
[0.12.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/releases/tag/v0.10.0
