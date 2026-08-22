import { mount } from '@vue/test-utils';
import Input from '../Input.vue';

// Final review, Important 11: `<label :for="id">` / `<input :id="id">`
// with `id` optional meant four call sites rendered both as `undefined` —
// a label pointing at nothing, and on the calendar a visible "Customer"
// label associated with no field at all. And the error <p> was never
// linked, so `aria-invalid="true"` fired with no announced reason.
it('associates the label with the input even when the caller passes no id', () => {
    const w = mount(Input, { props: { label: 'Customer' } });

    const id = w.get('input').attributes('id');

    expect(id).toBeTruthy();
    expect(id).not.toBe('undefined');
    expect(w.get('label').attributes('for')).toBe(id);
});

it('keeps an explicitly passed id, so existing callers and their tests are unaffected', () => {
    const w = mount(Input, { props: { label: 'Email', id: 'login-email' } });

    expect(w.get('input').attributes('id')).toBe('login-email');
    expect(w.get('label').attributes('for')).toBe('login-email');
});

it('gives each instance a distinct id, so two unlabelled fields on one page never collide', () => {
    // Mounted inside a single parent — `useId` is unique per app, and two
    // fields sharing a page is exactly the case a collision would break.
    const w = mount(
        {
            components: { Input },
            template: '<div><Input label="One" /><Input label="Two" /></div>',
        },
        { global: { components: { Input } } },
    );

    const [a, b] = w.findAll('input');

    expect(a.attributes('id')).toBeTruthy();
    expect(a.attributes('id')).not.toBe(b.attributes('id'));
});

it('links the error text to the input, so aria-invalid has an announced reason', () => {
    const w = mount(Input, { props: { label: 'Email', error: 'That email is already taken.' } });

    const input = w.get('input');
    expect(input.attributes('aria-invalid')).toBe('true');

    const describedBy = input.attributes('aria-describedby');
    expect(describedBy).toBeTruthy();
    expect(w.get(`#${describedBy}`).text()).toContain('That email is already taken.');
});

it('sets no aria-describedby when there is no error to point at', () => {
    const w = mount(Input, { props: { label: 'Email' } });

    expect(w.get('input').attributes('aria-describedby')).toBeUndefined();
});

// Task 1.3: tags carry a colour, and the manager needs a picker for it.
// Widening the union rather than dropping a raw <input type="color"> into
// TagManager keeps the label association, the generated id and the error
// plumbing this component already owns — all of which a bare input would
// have had to reimplement.
it('renders a colour picker with a real label when asked for type="color"', () => {
    const w = mount(Input, { props: { type: 'color', label: 'Tag colour', modelValue: '#0F726B' } });

    const input = w.get('input');

    expect(input.attributes('type')).toBe('color');
    expect(w.get('label').attributes('for')).toBe(input.attributes('id'));
});
