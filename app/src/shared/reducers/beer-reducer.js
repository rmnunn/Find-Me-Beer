export default (state = [], action) => {
	switch(action.type) {
		case "GET_ALL_BEER":
			return action.payload;
		default:
			return state;
	}
}