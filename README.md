# Checklist uso de equipamentos — plugin GLPI

Plugin para o [GLPI](https://glpi-project.org/) 10.0.x que controla a **retirada e a devolução de
equipamentos compartilhados do setor** (rádios comunicadores, celulares e tablets) com:

- **checklist sem digitação**: "Equipamento ok? Sim/Não" e, no Não, marcar problemas de um catálogo
  por tipo de equipamento;
- **selfie** do colaborador na retirada e na devolução (câmera do celular/tablet);
- **bloqueio do item** enquanto está com alguém e quando tem problema (só volta a circular quando o
  chamado do TI é solucionado);
- **conferência do gestor por turno**, com selfie do gestor e abertura automática de chamado;
- **registro de uso completo** de cada equipamento;
- **alerta** de equipamento não devolvido no fim do turno.

> Em desenvolvimento.

## Requisitos

- GLPI 10.0.x
- PHP 8.0+
- Plugin [Radios](https://github.com/tuliodutra27/GLPI-Plugin-Radios) (opcional — habilita o tipo
  Rádio; o tipo Telefone usa o ativo nativo do GLPI)

## Instalação

1. Copie o conteúdo deste repositório para a pasta `plugins/checklistitens` do GLPI
   (o nome da pasta precisa ser `checklistitens`).
2. Em **Configurar > Plugins**, instale e ative **Checklist uso de equipamentos**.
3. Em **Administração > Perfis**, aba **Checklist uso de equipamentos**, confira os direitos:
   - *Retirada e devolução*: concedido a todos os perfis na instalação;
   - *Conferência do setor (gestor)*: para o perfil do gestor;
   - *Administração (TI)*: configuração, liberação de itens e limpeza de selfies.
4. Em **Ativos > Checklist uso de equipamentos > Configuração**, confira estados, limites,
   turnos e categorias de chamado.

## Licença

GPL-3.0-or-later (ver [`LICENSE`](LICENSE)).
