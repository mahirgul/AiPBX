// ICE helpers shared by the web phone and the meeting room (JsSIP 3.1).
window.AiPBXIce = {
	// JsSIP 3.1 sends the SDP only when ICE gathering has finished; an unreachable TURN address
	// (UDP 3478 behind an edge that filters it) can hold that for seconds. Shortly after the first
	// relay candidate (at most 3 s after the first candidate) gathering is ended with what there is.
	capGathering(pc) {
		if (!pc || pc.__aipbxIceCap) return; // handlers may attach more than once per call
		pc.__aipbxIceCap = true;
		let done = false;
		let timer = null;
		const finish = () => {
			if (done || pc.iceGatheringState === "complete") return;
			done = true;
			try {
				pc.dispatchEvent(new RTCPeerConnectionIceEvent("icecandidate", { candidate: null }));
			} catch (e) {}
		};
		pc.addEventListener("icegatheringstatechange", () => {
			if (pc.iceGatheringState === "gathering") {
				done = false;
				clearTimeout(timer);
				timer = null;
			}
		});
		pc.addEventListener("icecandidate", (e) => {
			if (!e.candidate || done) return;
			if (/ typ relay /.test(e.candidate.candidate || "")) {
				clearTimeout(timer);
				timer = setTimeout(finish, 300);
			} else if (!timer) {
				timer = setTimeout(finish, 3000);
			}
		});
	},

	// ICE restart through a re-INVITE. JsSIP 3.1 sends the offer as soon as setLocalDescription
	// resolves; on a restart the gathering state still reads "complete" from the old round, so the
	// offer would leave without candidates and Asterisk answers without ICE (the call drops with
	// "Media Renegotiation Failed"). The local description is held until a relay candidate is in
	// it or the new gathering is done (4 s at most).
	restart(session) {
		const pc = session && session.connection;
		if (!pc || !session.isEstablished || !session.isEstablished()) return false;
		const orig = pc.setLocalDescription;
		let sawGathering = false;
		const onState = () => {
			if (pc.iceGatheringState === "gathering") sawGathering = true;
		};
		pc.addEventListener("icegatheringstatechange", onState);
		const cleanup = () => pc.removeEventListener("icegatheringstatechange", onState);
		const restore = () => {
			if (pc.setLocalDescription === setLocalDescription) pc.setLocalDescription = orig;
			cleanup();
		};
		function setLocalDescription(...args) {
			pc.setLocalDescription = orig;
			return Promise.resolve().then(() => orig.apply(pc, args)).then(
				() =>
					new Promise((resolve) => {
						const t0 = Date.now();
						(function poll() {
							const sdp = (pc.localDescription && pc.localDescription.sdp) || "";
							if (/ typ relay /.test(sdp) || (sawGathering && pc.iceGatheringState === "complete") || Date.now() - t0 > 4000) {
								cleanup();
								return resolve();
							}
							setTimeout(poll, 50);
						})();
					}),
			).catch((error) => {
				cleanup();
				throw error;
			});
		}
		pc.setLocalDescription = setLocalDescription;
		try {
			const started = session.renegotiate({ rtcOfferConstraints: { iceRestart: true } });
			if (!started) restore();
			return started;
		} catch (e) {
			restore();
			console.warn("ICE restart failed:", e);
			return false;
		}
	},

	// When the network changes during a call (Wi-Fi <-> mobile, a new address), the call's audio
	// gets a new path: a re-INVITE with fresh ICE credentials. Without a SIP socket it waits; call
	// onConnected() from the UA's "connected" event. Set up once per page.
	watchNetwork(getSession, getUA) {
		let pending = false;
		const restart = (why) => {
			const s = getSession();
			if (!s || !s.isEstablished || !s.isEstablished()) {
				pending = false;
				return;
			}
			const ua = getUA();
			if (!ua || !ua.isConnected || !ua.isConnected()) {
				pending = true;
				return;
			}
			pending = false;
			if (window.AiPBXIce.restart(s)) console.info("ICE restart:", why);
		};
		window.addEventListener("online", () => restart("back online"));
		if (navigator.connection && navigator.connection.addEventListener) {
			navigator.connection.addEventListener("change", () => restart("network changed"));
		}
		return {
			restart,
			onConnected() {
				if (pending) restart("SIP socket back after a network change");
			},
		};
	},
};
