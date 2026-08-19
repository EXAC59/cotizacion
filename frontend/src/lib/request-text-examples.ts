export const REQUEST_TEXT_EXAMPLES = {
  oficina: `2 Monitor Dell P2422H 24 pulgadas
3 Teclado Logitech K120
10 Cable UTP Cat6 3m
1 Switch Cisco C9200L-24T-4G-E`,

  almacen: `4 USB Kingston DataTraveler 128GB (DTX/128GB)
2 Tóner HP 85A (CE285A)
5 Memoria Kingston 16GB DDR4 KVR16N11/16`,

  corto: `1 pieza Tóner HP 85A (CE285A) — HP
5 Cisco C9200L-24T-4G-E`,
} as const

export type RequestTextExampleKey = keyof typeof REQUEST_TEXT_EXAMPLES
