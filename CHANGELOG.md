# Changelog

Todas as mudanças relevantes deste plugin ficam registradas aqui.
O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e as versões
seguem o [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não lançado]

## [0.11.0-rc.2] - 2026-10-15

Requer Atualizar em Configurar > Plugins.

### Adicionado
- Recebimento da embarcação: baterias e capas ganham pergunta própria ("estão todas boas?"). Com
  "Não", cada peça com defeito tem um bloco em destaque com os problemas do catálogo e de 1 a 5
  fotos, e ao concluir o plugin abre um chamado para o TI com tudo isso.
- Catálogo de problemas de bateria e de capa, editável pelo TI.

### Alterado
- "Itens ok?" passa a se chamar "Rádios ok?": a pergunta é só sobre os rádios.

## [0.11.0-rc.1] - 2026-10-14

Requer Atualizar em Configurar > Plugins.

### Adicionado
- Entrega de rádios para embarcação: o gestor separa vários rádios, as baterias reserva e as
  capas, fotografa, tira a selfie e entrega a uma embarcação cadastrada pelo TI.
- Recebimento de volta da embarcação no mesmo modelo, inclusive em partes, com fotos e selfie.
- No recebimento, as baterias e as capas devolvidas ficam limitadas às que foram entregues; o
  gestor marca quantas baterias voltaram com defeito e se as capas estão em bom estado.
- Rádio com problema na entrega ou no recebimento: problema novo bloqueia o rádio; problema já
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

[Não lançado]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.11.0-rc.2...HEAD
[0.11.0-rc.2]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.11.0-rc.1...v0.11.0-rc.2
[0.11.0-rc.1]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.10.0...v0.11.0-rc.1
[0.10.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/releases/tag/v0.10.0
