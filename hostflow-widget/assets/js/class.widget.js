/* Host Flow dashboard widget: a live diagram in an iframe (same origin). */
class CWidgetHostFlow extends CWidget {

	onStart() {
		this._events = {
			...this._events,
			mousedown: () => {
				// While the dashboard is being edited, let drags pass over the iframe.
				if (this._is_edit_mode) {
					const iframe = this._contents.querySelector('iframe');
					if (iframe !== null) {
						iframe.style.pointerEvents = 'none';
						addEventListener('mouseup', this._events.mouseup, {once: true});
					}
				}
			},
			mouseup: () => {
				const iframe = this._contents.querySelector('iframe');
				if (iframe !== null) {
					iframe.style.pointerEvents = '';
				}
			}
		};
	}

	onActivate() {
		this._target.addEventListener('mousedown', this._events.mousedown);
	}

	onDeactivate() {
		this._target.removeEventListener('mousedown', this._events.mousedown);
	}

	promiseReady() {
		const readiness = [super.promiseReady()];
		const iframe = this._target.querySelector('iframe');
		if (iframe !== null) {
			readiness.push(new Promise((resolve) => iframe.addEventListener('load', () => setTimeout(resolve, 300))));
		}
		return Promise.all(readiness);
	}
}
