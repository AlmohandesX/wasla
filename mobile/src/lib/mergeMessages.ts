export type Message = {
  id: number;
  thread_id: number;
  sender: 'visitor' | 'owner';
  body: string;
  read: boolean;
  reported: boolean;
  created_at: string;
};

const same = (a: Message, b: Message) =>
  a.body === b.body && a.read === b.read && a.reported === b.reported && a.sender === b.sender;

/**
 * Folds newly fetched messages into the list: one copy per id (the newer copy wins, so a read or reported
 * flag updates), sorted by id. Returns `current` itself when nothing changed, so no re-render follows.
 */
export function mergeMessages(current: Message[], incoming: Message[]): Message[] {
  const byId = new Map<number, Message>();
  for (const m of current) byId.set(m.id, m);
  let changed = false;
  for (const m of incoming) {
    const held = byId.get(m.id);
    if (!held || !same(held, m)) {
      byId.set(m.id, m);
      changed = true;
    }
  }
  return changed ? [...byId.values()].sort((a, b) => a.id - b.id) : current;
}
